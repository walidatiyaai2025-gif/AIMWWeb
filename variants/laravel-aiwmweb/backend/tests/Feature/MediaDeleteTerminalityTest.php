<?php

namespace Tests\Feature;

use App\Content\MediaDeleteService;
use App\Http\Controllers\ContentApiController;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MediaDeleteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-4DCB58743D';

    public function test_exact_critical_canonical_operation_is_terminalized(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('critical', $operation['risk']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/MediaManager.razor',
            $operation['current_source'],
        );
    }

    public function test_route_is_session_tenant_permission_csrf_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/1/media/2', 'DELETE'),
        );

        $this->assertSame(
            ContentApiController::class.'@deleteMedia',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('api.v1.media.delete-permanently', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['DELETE'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $frontend = (string) file_get_contents(resource_path('js/core.ts'));
        $this->assertStringContainsString("validateCsrfTokens(except: ['api/v1/billing/webhooks/paypal'])", $bootstrap);
        $this->assertStringContainsString("headers.set('X-CSRF-TOKEN', csrf)", $frontend);
    }

    public function test_permission_foreign_tenant_foreign_site_and_foreign_media_fail_closed(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['content.view']);
        [$limitedSite, $limitedMedia] = $this->siteAndMedia($limitedMembership, 501);

        $this->actingAs($limited)
            ->deleteJson("/api/v1/tenants/limited/sites/{$limitedSite->id}/media/{$limitedMedia->id}")
            ->assertForbidden(); // 403

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite, $alphaMedia] = $this->siteAndMedia($alpha, 601);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['content.view', 'content.edit']);
        [$betaSite, $betaMedia] = $this->siteAndMedia($beta, 602);

        $this->actingAs($alphaUser)
            ->deleteJson("/api/v1/tenants/beta/sites/{$betaSite->id}/media/{$betaMedia->id}")
            ->assertNotFound(); // foreign tenant 404

        $this->actingAs($alphaUser)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/media/{$betaMedia->id}")
            ->assertNotFound(); // foreign site 404

        $this->actingAs($alphaUser)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/media/{$betaMedia->id}")
            ->assertNotFound(); // foreign media 404

        $this->assertDatabaseHas('media_items', ['id' => $alphaMedia->id]);
        $this->assertDatabaseHas('media_items', ['id' => $betaMedia->id]);
    }

    public function test_delete_rejects_caller_owned_fields_and_featured_media_references(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->siteAndMedia($membership, 701);
        $this->credential($membership, $site);

        $this->actingAs($user)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}", [
                'tenant_id' => $membership->tenant_id,
                'site_id' => $site->id,
                'remote_id' => 999999,
            ])
            ->assertUnprocessable();

        $this->inTenant($membership, function () use ($site, $media): void {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 1001,
                'type' => 'post',
                'featured_media_remote_id' => $media->remote_id,
            ]);
        });

        $this->actingAs($user)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}")
            ->assertConflict();

        $this->assertDatabaseHas('media_items', ['id' => $media->id]);
        Http::assertNothingSent();
    }

    public function test_permanent_delete_uses_real_wordpress_force_delete_and_authoritative_reread(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->siteAndMedia($membership, 801);
        $this->credential($membership, $site);

        Http::fakeSequence()
            ->push(['id' => 801], 200)
            ->push(['deleted' => true, 'previous' => ['id' => 801]], 200)
            ->push(['code' => 'rest_post_invalid_id'], 404)
            ->push(['code' => 'rest_post_invalid_id'], 404);

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}");

        $response
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('remote_verified_absent', true)
            ->assertJsonPath('retry_recovered', false);

        $this->assertDatabaseMissing('media_items', ['id' => $media->id]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'media.deleted_permanently',
            'subject_type' => 'media',
            'subject_id' => (string) $media->id,
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), "/wp-json/wp/v2/media/801"));
    }

    public function test_lost_response_retry_recovers_from_remote_absence_without_second_delete(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->siteAndMedia($membership, 901);
        $this->credential($membership, $site);

        Http::fakeSequence()
            ->push(['code' => 'rest_post_invalid_id'], 404)
            ->push(['code' => 'rest_post_invalid_id'], 404);

        $this->actingAs($user)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('retry_recovered', true);

        $this->assertDatabaseMissing('media_items', ['id' => $media->id]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_wordpress_failure_and_missing_credentials_never_fabricate_success_or_delete_local_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->siteAndMedia($membership, 1001);

        $this->actingAs($user)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}")
            ->assertStatus(503);

        $this->assertDatabaseHas('media_items', ['id' => $media->id]);

        $this->credential($membership, $site);
        Http::fakeSequence()
            ->push(['id' => 1001], 200)
            ->push(['message' => 'upstream failure'], 500)
            ->push(['id' => 1001], 200);

        $this->actingAs($user)
            ->deleteJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('media_items', ['id' => $media->id]);
    }

    public function test_source_and_laravel_contracts_prove_force_delete_no_secret_exposure_and_visible_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/MediaManager.razor'),
        );
        $service = (string) file_get_contents(app_path('Content/MediaDeleteService.php'));
        $wordpress = (string) file_get_contents(app_path('Content/Remote/NativeWordPressRestPath.php'));
        $control = (string) file_get_contents(resource_path('js/media-delete-control.tsx'));

        $this->assertStringContainsString('ConfirmDeleteAsync', $source);
        $this->assertStringContainsString('MediaService.DeleteAsync(SiteId, deletedId)', $source);
        $this->assertStringContainsString('await RefreshLocalAsync()', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $service);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("authorize('content.edit')", (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php')));
        $this->assertStringContainsString("['force' => true]", $wordpress);
        $this->assertStringContainsString('requestWithoutRetry', $wordpress);
        $this->assertStringContainsString("where('site_id', $siteId)", $service);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString('remote_verified_absent', $service);
        $this->assertStringContainsString("method: 'DELETE'", $control);
        $this->assertStringContainsString('role="alertdialog"', $control);

        $combined = strtolower($service."\n".$control);
        $this->assertStringNotContainsString('application_password', $combined);
        $this->assertStringNotContainsString('secret_value', $combined);
        $this->assertStringNotContainsString('api_key', $combined);
        $this->assertStringNotContainsString('client_secret', $combined);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "media-delete-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function siteAndMedia(TenantMembership $membership, int $remoteId): array
    {
        return $this->inTenant($membership, function () use ($remoteId): array {
            $site = Site::query()->create([
                'name' => "Media {$remoteId}",
                'url' => "https://media-{$remoteId}.example.test",
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
            $media = MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $remoteId,
                'title' => "media-{$remoteId}.jpg",
                'mime_type' => 'image/jpeg',
            ]);

            return [$site, $media];
        });
    }

    private function credential(TenantMembership $membership, Site $site): SiteCredential
    {
        return $this->inTenant($membership, fn () => SiteCredential::query()->create([
            'site_id' => $site->id,
            'username' => 'editor',
            'secret_value' => 'application-password-1234',
        ]));
    }

    private function inTenant(TenantMembership $membership, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
