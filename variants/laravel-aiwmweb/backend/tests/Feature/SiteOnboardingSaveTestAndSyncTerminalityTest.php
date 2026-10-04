<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOnboardingController;
use App\Jobs\SyncSiteJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteOnboardingSaveTestAndSyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-2EF6B8A27A';

    public function test_exact_mutation_route_is_guarded_and_bound_to_the_canonical_operation(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/onboarding', 'POST'));

        $this->assertSame(SiteOnboardingController::class.'@store', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_real_boundary_persists_encrypted_credentials_verifies_then_queues_one_initial_sync_with_safe_replay(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        Queue::fake();
        Http::fake([
            'https://wp.example.test/*' => Http::response([
                'id' => 77,
                'capabilities' => ['manage_options' => true],
            ], 200),
        ]);

        $payload = [
            'name' => 'Alpha WordPress',
            'url' => 'https://wp.example.test',
            'username' => 'wp-admin',
            'application_password' => 'abcd efgh ijkl mnop',
        ];

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'onboarding-alpha-1')
            ->postJson('/api/tenants/alpha/sites/onboarding', $payload)
            ->assertAccepted()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('site.connection_status', 'verified')
            ->assertJsonPath('credential_configured', true)
            ->assertJsonPath('sync.status', 'queued')
            ->assertJsonPath('idempotent_replay', false);

        $siteId = (int) $first->json('site.id');
        $runId = (int) $first->json('sync.id');

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with(
            $request->url(),
            'https://wp.example.test/wp-json/wp/v2/users/me'
        ));

        $rawSecret = DB::table('site_credentials')->where('site_id', $siteId)->value('secret_value');
        $this->assertIsString($rawSecret);
        $this->assertNotSame($payload['application_password'], $rawSecret);
        $this->assertStringNotContainsString($payload['application_password'], $first->getContent());

        Queue::assertPushed(SyncSiteJob::class, fn (SyncSiteJob $job): bool => $job->tenantId === $tenant->id
            && $job->siteId === $siteId
            && $job->syncRunId === $runId);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'onboarding-alpha-1')
            ->postJson('/api/tenants/alpha/sites/onboarding', $payload)
            ->assertOk()
            ->assertJsonPath('site.id', $siteId)
            ->assertJsonPath('sync.id', $runId)
            ->assertJsonPath('idempotent_replay', true);

        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('site_credentials', 1);
        $this->assertDatabaseCount('sync_runs', 1);
        Queue::assertPushed(SyncSiteJob::class, 1);

        $this->assertDatabaseHas('audit_events', [
            'event' => 'site_onboarding.credentials_saved',
            'subject_type' => 'site',
            'subject_id' => (string) $siteId,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'site_onboarding.initial_sync_queued',
            'subject_type' => 'site',
            'subject_id' => (string) $siteId,
        ]);
    }

    public function test_failed_wordpress_test_remains_failed_persists_protected_setup_and_never_starts_sync(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        Queue::fake();
        Http::fake(['https://bad.example.test/*' => Http::response(['code' => 'rest_forbidden'], 401)]);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'onboarding-failed-1')
            ->postJson('/api/tenants/alpha/sites/onboarding', [
                'name' => 'Bad WordPress',
                'url' => 'https://bad.example.test',
                'username' => 'wp-admin',
                'application_password' => 'wrong-password-value',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('site.connection_status', 'failed')
            ->assertJsonPath('credential_configured', true)
            ->assertJsonPath('sync', null)
            ->assertJsonPath('idempotent_replay', false);

        $siteId = (int) $response->json('site.id');
        $this->assertDatabaseHas('sites', ['id' => $siteId, 'connection_status' => 'failed']);
        $this->assertDatabaseHas('site_credentials', ['site_id' => $siteId]);
        $this->assertDatabaseCount('sync_runs', 0);
        Queue::assertNothingPushed();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'onboarding-failed-1')
            ->postJson('/api/tenants/alpha/sites/onboarding', [
                'name' => 'Bad WordPress',
                'url' => 'https://bad.example.test',
                'username' => 'wp-admin',
                'application_password' => 'wrong-password-value',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('site_credentials', 1);
        Queue::assertNothingPushed();
    }

    public function test_failed_connection_can_be_retried_with_a_new_key_without_duplicate_site_or_unsafe_sync(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        Queue::fake();

        $payload = [
            'name' => 'Retry WordPress',
            'url' => 'https://retry.example.test',
            'username' => 'wp-admin',
            'application_password' => 'wrong-password-value',
        ];

        Http::fake(['https://retry.example.test/*' => Http::response(['code' => 'rest_forbidden'], 401)]);

        $failed = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'retry-failed-1')
            ->postJson('/api/tenants/alpha/sites/onboarding', $payload)
            ->assertUnprocessable();

        $siteId = (int) $failed->json('site.id');
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('site_credentials', 1);
        $this->assertDatabaseCount('sync_runs', 0);
        Queue::assertNothingPushed();

        Http::fake([
            'https://retry.example.test/*' => Http::response([
                'id' => 88,
                'capabilities' => ['manage_options' => true],
            ], 200),
        ]);

        $payload['application_password'] = 'correct-password-value';

        $retried = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'retry-success-2')
            ->postJson('/api/tenants/alpha/sites/onboarding', $payload)
            ->assertAccepted()
            ->assertJsonPath('site.id', $siteId)
            ->assertJsonPath('site.connection_status', 'verified')
            ->assertJsonPath('sync.status', 'queued')
            ->assertJsonPath('idempotent_replay', false);

        $this->assertStringNotContainsString($payload['application_password'], $retried->getContent());
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseCount('site_credentials', 1);
        $this->assertDatabaseCount('sync_runs', 1);

        Queue::assertPushed(
            SyncSiteJob::class,
            fn (SyncSiteJob $job): bool => $job->tenantId === $tenant->id && $job->siteId === $siteId,
        );
        Queue::assertPushed(SyncSiteJob::class, 1);
    }

    public function test_guest_missing_permission_foreign_tenant_and_caller_owned_identity_fail_closed(): void
    {
        $this->postJson('/api/tenants/alpha/sites/onboarding', [])->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->withHeader('Idempotency-Key', 'limited-1')
            ->postJson('/api/tenants/limited/sites/onboarding', [
                'name' => 'Denied',
                'url' => 'https://denied.example.test',
                'username' => 'user',
                'application_password' => 'password-value',
            ])
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'sites.manage']);
        Tenant::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);

        $this->actingAs($authorized)
            ->withHeader('Idempotency-Key', 'foreign-1')
            ->postJson('/api/tenants/beta/sites/onboarding', [
                'name' => 'Foreign',
                'url' => 'https://foreign.example.test',
                'username' => 'user',
                'application_password' => 'password-value',
            ])
            ->assertNotFound();

        $this->actingAs($authorized)
            ->withHeader('Idempotency-Key', 'identity-1')
            ->postJson('/api/tenants/alpha/sites/onboarding', [
                'name' => 'No caller ownership',
                'url' => 'https://safe.example.test',
                'username' => 'user',
                'application_password' => 'password-value',
                'tenant_id' => 999,
                'site_id' => 999,
                'actor_user_id' => 999,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_credentials', 0);
        $this->assertDatabaseCount('sync_runs', 0);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-onboarding-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
