<?php

namespace Tests\Feature;

use App\Http\Controllers\WordPressWriteSmokeController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RemoteRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WordPressWriteSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/tenants/alpha/runtime-inspector/wordpress-write-check';

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        parent::tearDown();
    }

    private function body(string $resource = 'posts'): array
    {
        return [
            'resource' => $resource,
            'confirmation' => 'RUN_DISPOSABLE_WORDPRESS_TEST',
        ];
    }

    public function test_route_requires_post_auth_tenant_context_and_explicit_confirmation(): void
    {
        $route = Route::getRoutes()->match(Request::create(self::ENDPOINT, 'POST'));
        $this->assertSame(WordPressWriteSmokeController::class, $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertContains('throttle:12,1', $route->gatherMiddleware());

        Http::fake();
        $this->postJson(self::ENDPOINT, $this->body() + ['site_id' => 1])->assertUnauthorized();
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($member);
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['site_id' => $site->id, 'resource' => 'posts'])
            ->assertUnprocessable();
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->body() + ['site_id' => $site->id, 'remote_id' => 99])
            ->assertUnprocessable();
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->body() + ['site_id' => $site->id, 'payload' => ['title' => 'real post']])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_missing_permission_and_cross_tenant_site_fail_without_remote_calls(): void
    {
        $limited = User::factory()->create();
        $a = $this->membership($limited, 'alpha', ['diagnostics.view', 'content.edit']);
        $siteA = $this->site($a);

        $other = User::factory()->create();
        $b = $this->membership($other, 'beta', ['diagnostics.view', 'content.edit', 'content.manage']);
        $siteB = $this->site($b);
        Http::fake();

        $this->actingAs($limited)
            ->postJson(self::ENDPOINT, $this->body() + ['site_id' => $siteA->id])
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $this->actingAs($authorized)
            ->postJson(self::ENDPOINT, $this->body() + ['site_id' => $siteB->id])
            ->assertNotFound();
        $this->actingAs($authorized)
            ->postJson('/tenants/beta/runtime-inspector/wordpress-write-check',
                $this->body() + ['site_id' => $siteB->id])
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_missing_application_password_is_not_reported_as_success(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        Http::fake();
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->body() + ['site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('state', 'not_tested')
            ->assertJsonPath('reason', 'application_password_not_configured')
            ->assertJsonPath('save_verified', false);
        Http::assertNothingSent();
    }

    public function test_real_http_post_get_save_get_delete_get_is_required_for_pass(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($membership);
        $this->credential($membership, $site);

        $title = '';
        $existing = false;
        $requests = [];
        Http::fake(function (RemoteRequest $request) use (&$title, &$existing, &$requests) {
            $method = $request->method();
            $url = $request->url();
            $requests[] = $method.' '.$url;

            if ($method === 'POST' && str_ends_with($url, '/wp-json/wp/v2/posts')) {
                $this->assertFalse($existing, 'Probe must never reuse an existing WordPress post.');
                $this->assertSame('draft', $request->data()['status'] ?? null);
                $title = $request->data()['title'];
                $this->assertStringStartsWith('aiwm-probe-', $title);
                $existing = true;

                return Http::response(['id' => 321, 'title' => ['raw' => $title]], 201);
            }
            if ($method === 'POST' && str_ends_with($url, '/posts/321')) {
                $title = $request->data()['title'];

                return Http::response(['id' => 321], 200);
            }
            if ($method === 'DELETE' && str_ends_with($url, '/posts/321')) {
                $this->assertSame(true, $request->data()['force'] ?? false);
                $existing = false;

                return Http::response(['deleted' => true], 200);
            }
            if ($method === 'GET' && str_contains($url, '/posts/321')) {
                return $existing ? Http::response(['id' => 321, 'title' => ['raw' => $title]], 200)
                    : Http::response(['code' => 'rest_post_invalid_id'], 404);
            }

            return Http::response(['code' => 'unexpected'], 500);
        });

        $response = $this->actingAs($user)->postJson(
            self::ENDPOINT, $this->body() + ['site_id' => $site->id],
        );
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('schema', 'aiwm.wordpress-write-smoke.v1')
            ->assertJsonPath('transport', 'native_wordpress_rest')
            ->assertJsonPath('resource', 'posts')
            ->assertJsonPath('state', 'passed')
            ->assertJsonPath('create_verified', true)
            ->assertJsonPath('save_verified', true)
            ->assertJsonPath('delete_verified', true)
            ->assertJsonPath('cleanup_required', false)
            ->assertJsonPath('recovery', null);
        $this->assertCount(6, $requests);
        $this->assertSame(['POST', 'GET', 'POST', 'GET', 'DELETE', 'GET'],
            array_map(fn ($line): string => explode(' ', $line)[0], $requests));
        $this->assertFalse($existing);
        $this->assertStringNotContainsString('APPLICATION_SECRET_CANARY', $response->getContent());
    }

    public function test_save_failure_attempts_cleanup_and_does_not_claim_a_pass(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);
        $title = '';
        $sent = [];
        Http::fake(function (RemoteRequest $request) use (&$title, &$sent) {
            $method = $request->method();
            $url = $request->url();
            $sent[] = $method;
            if ($method === 'POST' && str_ends_with($url, '/posts')) {
                $title = $request->data()['title'];

                return Http::response(['id' => 777], 201);
            }
            if ($method === 'POST' && str_ends_with($url, '/posts/777')) {
                return Http::response(['code' => 'rest_forbidden'], 403);
            }
            if ($method === 'DELETE') {
                return Http::response(['deleted' => true], 200);
            }
            if ($method === 'GET' && str_contains($url, '/posts/777')) {
                return count($sent) >= 5
                    ? Http::response(['code' => 'not_found'], 404)
                    : Http::response(['id' => 777, 'title' => ['raw' => $title]], 200);
            }

            return Http::response([], 500);
        });

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->body() + ['site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('save_verified', false)
            ->assertJsonPath('delete_verified', true)
            ->assertJsonPath('reason', 'remote_save_not_confirmed');
        $this->assertSame(['POST', 'GET', 'POST', 'DELETE', 'GET'], $sent);
    }

    public function test_unverified_cleanup_is_reported_with_recovery_and_no_delete_retry(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);
        $title = '';
        Http::fake(function (RemoteRequest $request) use (&$title) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/posts')) {
                $title = $request->data()['title'];

                return Http::response(['id' => 952], 201);
            }
            if ($request->method() === 'POST') {
                $title = $request->data()['title'];

                return Http::response(['id' => 952], 200);
            }
            if ($request->method() === 'DELETE') {
                return Http::response([], 500);
            }

            return Http::response(['id' => 952, 'title' => ['raw' => $title]], 200);
        });
        $response = $this->actingAs($user)->postJson(
            self::ENDPOINT, $this->body() + ['site_id' => $site->id],
        );
        $response->assertOk()->assertJsonPath('state', 'needs_reconciliation')
            ->assertJsonPath('cleanup_required', true)
            ->assertJsonPath('delete_verified', false)
            ->assertJsonPath('recovery.known_objects.0.remote_id', 952);
        $this->assertStringContainsString('aiwm-probe-', $response->json('recovery.marker'));
        Http::assertSentCount(5);
        Http::assertSent(fn (RemoteRequest $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/posts/952'));
    }

    public function test_all_six_resource_types_are_read_saved_then_deleted_without_touching_prior_content(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($membership);
        $this->credential($membership, $site);

        $rows = [];
        $sequence = [];
        $nextId = 1500;

        Http::fake(function (RemoteRequest $request) use (&$rows, &$sequence, &$nextId) {
            $url = $request->url();
            $method = $request->method();
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#/wp-json/wp/v2/(posts|pages|categories|tags|comments|media)(?:/([0-9]+))?$#', $path, $parts) !== 1) {
                return Http::response(['code' => 'unexpected'], 500);
            }
            $resource = $parts[1];
            $remoteId = isset($parts[2]) ? (int) $parts[2] : null;
            $key = $resource.':'.$remoteId;
            $data = $request->data();
            $sequence[] = $method.':'.$resource;

            if ($method === 'POST' && $remoteId === null) {
                $createdId = ++$nextId;
                $rows[$resource.':'.$createdId] = [
                    'id' => $createdId,
                    'name' => $data['name'] ?? '',
                    'title' => ['raw' => $data['title'] ?? ($resource === 'media' ? 'aiwm-probe-'.str_repeat('a', 16) : '')],
                    'content' => ['raw' => $data['content'] ?? ''],
                    'alt_text' => '',
                ];

                return Http::response(['id' => $createdId], 201);
            }
            if ($method === 'POST' && $remoteId !== null && isset($rows[$key])) {
                if (isset($data['name'])) {
                    $rows[$key]['name'] = $data['name'];
                }
                if (isset($data['title'])) {
                    $rows[$key]['title']['raw'] = $data['title'];
                }
                if (isset($data['content'])) {
                    $rows[$key]['content']['raw'] = $data['content'];
                }
                if (isset($data['alt_text'])) {
                    $rows[$key]['alt_text'] = $data['alt_text'];
                }

                return Http::response(['id' => $remoteId], 200);
            }
            if ($method === 'DELETE' && isset($rows[$key])) {
                $this->assertSame(true, $data['force'] ?? false);
                unset($rows[$key]);

                return Http::response(['deleted' => true], 200);
            }
            if ($method === 'GET' && isset($rows[$key])) {
                return Http::response($rows[$key], 200);
            }
            if ($method === 'GET') {
                return Http::response(['code' => 'rest_not_found'], 404);
            }

            return Http::response(['code' => 'unexpected'], 400);
        });

        // Model the media title derived from the generated filename in WP REST.
        // The real implementation must confirm this marker on GET before DELETE.
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('a', $length));
        foreach (['posts', 'pages', 'categories', 'tags', 'comments', 'media'] as $resource) {
            $this->actingAs($user)
                ->postJson(self::ENDPOINT, $this->body($resource) + ['site_id' => $site->id])
                ->assertOk()
                ->assertJsonPath('resource', $resource)
                ->assertJsonPath('state', 'passed')
                ->assertJsonPath('save_verified', true)
                ->assertJsonPath('delete_verified', true)
                ->assertJsonPath('cleanup_required', false);
            $this->assertSame([], $rows, 'Every synthetic WordPress object must be deleted after each resource probe.');
        }
        $this->assertCount(40, $sequence);
        $this->assertSame(7, count(array_filter($sequence, fn (string $entry): bool => str_starts_with($entry, 'DELETE:'))));
    }

    public function test_corrupt_created_post_identity_never_permanently_deletes_an_existing_post(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);

        Http::fake(function (RemoteRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/posts')) {
                return Http::response(['id' => 778], 201);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/posts/778')) {
                return Http::response(['id' => 778, 'title' => ['raw' => 'Real existing customer article']], 200);
            }

            $this->fail('Must never DELETE or mutate a remote ID whose probe marker was not confirmed.');
        });

        $response = $this->actingAs($user)->postJson(self::ENDPOINT, $this->body() + ['site_id' => $site->id]);
        $response->assertOk()->assertJsonPath('state', 'needs_reconciliation')
            ->assertJsonPath('cleanup_required', true)
            ->assertJsonPath('create_verified', false)
            ->assertJsonPath('delete_verified', false)
            ->assertJsonPath('recovery.known_objects.0.remote_id', 778);
        $this->assertStringStartsWith('aiwm-probe-', $response->json('recovery.marker'));
        Http::assertSentCount(2);
    }

    public function test_initial_wordpress_read_failure_does_not_destroy_an_unverified_resource(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);

        Http::fake(function (RemoteRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/pages')) {
                return Http::response(['id' => 119], 201);
            }
            if ($request->method() === 'GET') {
                return Http::response(['code' => 'upstream_unavailable'], 503);
            }

            $this->fail('No DELETE should follow a failed verification GET.');
        });

        $this->actingAs($user)->postJson(self::ENDPOINT, $this->body('pages') + ['site_id' => $site->id])
            ->assertOk()->assertJsonPath('state', 'needs_reconciliation')
            ->assertJsonPath('reason', 'cleanup_not_verified')
            ->assertJsonPath('recovery.known_objects.0.remote_id', 119)
            ->assertJsonPath('delete_verified', false);
        Http::assertSentCount(2);
    }

    public function test_unverified_media_upload_cannot_delete_an_unrelated_media_item(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);

        Http::fake(function (RemoteRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/media')) {
                return Http::response(['id' => 912], 201);
            }
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => 912, 'title' => ['raw' => 'Existing family portrait'],
                    'slug' => 'family-portrait', 'source_url' => 'https://wordpress.example.test/uploads/family.png',
                ], 200);
            }

            $this->fail('No save or DELETE is permitted for media without the generated filename marker.');
        });

        $this->actingAs($user)->postJson(self::ENDPOINT, $this->body('media') + ['site_id' => $site->id])
            ->assertOk()->assertJsonPath('state', 'needs_reconciliation')
            ->assertJsonPath('cleanup_required', true)
            ->assertJsonPath('recovery.known_objects.0.remote_id', 912)
            ->assertJsonPath('delete_verified', false);
        Http::assertSentCount(2);
    }

    public function test_marker_owned_media_can_be_verified_updated_and_safely_deleted(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('b', $length));

        $exists = false;
        $alt = '';
        Http::fake(function (RemoteRequest $request) use (&$exists, &$alt) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                $exists = true;

                return Http::response(['id' => 919], 201);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/media/919')) {
                $alt = (string) ($request->data()['alt_text'] ?? '');

                return Http::response(['id' => 919], 200);
            }
            if ($request->method() === 'DELETE') {
                $this->assertTrue($exists);
                $exists = false;

                return Http::response(['deleted' => true], 200);
            }
            if ($request->method() === 'GET') {
                return $exists
                    ? Http::response([
                        'id' => 919, 'slug' => 'aiwm-probe-'.str_repeat('b', 16),
                        'title' => ['raw' => 'Canary upload'],
                        'alt_text' => $alt,
                    ], 200)
                    : Http::response(['code' => 'rest_media_invalid_id'], 404);
            }
            $this->fail('Unexpected media probe method.');
        });

        $this->actingAs($user)->postJson(self::ENDPOINT, $this->body('media') + ['site_id' => $site->id])
            ->assertOk()->assertJsonPath('state', 'passed')
            ->assertJsonPath('save_verified', true)
            ->assertJsonPath('delete_verified', true)
            ->assertJsonPath('cleanup_required', false);
        $this->assertFalse($exists);
        Http::assertSentCount(6);
    }

    public function test_comment_probe_parent_is_not_deleted_without_confirmed_parent_marker(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);
        Http::fake(function (RemoteRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/posts')) {
                return Http::response(['id' => 531], 201);
            }
            if ($request->method() === 'GET') {
                return Http::response(['id' => 531, 'title' => ['raw' => 'Unrelated published article']], 200);
            }

            $this->fail('Must not create comment or DELETE unverified parent.');
        });

        $this->actingAs($user)->postJson(self::ENDPOINT, $this->body('comments') + ['site_id' => $site->id])
            ->assertOk()->assertJsonPath('state', 'needs_reconciliation')
            ->assertJsonPath('recovery.known_objects.0.remote_id', 531)
            ->assertJsonPath('cleanup_required', true);
        Http::assertSentCount(2);
    }

    public function test_boolean_float_and_fractional_ids_cannot_be_coerced_to_existing_wordpress_records(): void
    {
        $user = User::factory()->create();
        $m = $this->membership($user, 'alpha', ['diagnostics.view', 'content.edit', 'content.manage']);
        $site = $this->site($m);
        $this->credential($m, $site);
        $invalidIds = [true, 12.0, '12.5'];
        $attempt = 0;
        Http::fake(function (RemoteRequest $request) use ($invalidIds, &$attempt) {
            $this->assertSame('POST', $request->method());
            $this->assertLessThan(count($invalidIds), $attempt);

            return Http::response(['id' => $invalidIds[$attempt++]], 201);
        });

        foreach ($invalidIds as $_) {
            $this->actingAs($user)->postJson(self::ENDPOINT, $this->body() + ['site_id' => $site->id])
                ->assertOk()->assertJsonPath('state', 'needs_reconciliation')
                ->assertJsonPath('reason', 'create_result_ambiguous')
                ->assertJsonPath('cleanup_required', true)
                ->assertJsonPath('delete_verified', false)
                ->assertJsonCount(0, 'recovery.known_objects');
        }
        $this->assertSame(count($invalidIds), $attempt);
        Http::assertSentCount(count($invalidIds));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'wp-smoke-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'WordPress synthetic audit target', 'url' => 'https://wordpress.example.test',
            'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function credential(TenantMembership $membership, Site $site): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        SiteCredential::query()->create([
            'site_id' => $site->id, 'username' => 'audit-wp-user',
            'secret_value' => 'APPLICATION_SECRET_CANARY',
        ]);
        app(TenantContext::class)->forget();
    }
}
