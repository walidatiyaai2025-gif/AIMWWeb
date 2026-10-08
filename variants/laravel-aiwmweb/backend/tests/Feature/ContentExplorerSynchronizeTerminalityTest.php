<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoController;
use App\Jobs\SyncSiteJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerSynchronizeTerminalityTest extends TestCase
{
    use RefreshDatabase;

    // Exact-head CI anchor after official AIMW-BILL-5DC460397B parity materialization.
    private const OPERATION_ID = 'AIMW-BILL-5DC460397B';

    public function test_source_route_and_visible_laravel_control_bind_exact_synchronize_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));
        $service = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Services/WordPressSyncWebService.cs'));
        $frontend = (string) file_get_contents(resource_path('js/content-explorer-synchronize-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('private async Task SynchronizeAsync()', $source);
        $this->assertStringContainsString('SyncService.SynchronizeAsync(Id)', $source);
        $this->assertStringContainsString('await ReloadDataAsync(false);', $source);
        $this->assertStringContainsString('EnsureOwnershipAsync(siteId', $service);
        $this->assertStringContainsString('ContentExplorerSynchronizeControl', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('Idempotency-Key', $frontend);
        $this->assertStringContainsString('queryClient.invalidateQueries', $frontend);

        $sync = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/sync', 'POST'));
        $this->assertSame(DemoController::class.'@sync', ltrim($sync->getActionName(), '\\'));
        $this->assertContains('web', $sync->gatherMiddleware());
        $this->assertContains('auth', $sync->gatherMiddleware());
        $this->assertContains('tenant.context', $sync->gatherMiddleware());

        $status = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sync-runs/9', 'GET'));
        $this->assertSame(DemoController::class.'@syncStatus', ltrim($status->getActionName(), '\\'));
        $this->assertContains('auth', $status->gatherMiddleware());
        $this->assertContains('tenant.context', $status->gatherMiddleware());
    }

    public function test_guest_permission_and_foreign_tenant_site_fail_closed_without_sync_dispatch(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha, ['tenant.view', 'sites.view'], 'alpha-viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertUnauthorized();

        $this->actingAs($viewer)
            ->withHeader('Idempotency-Key', 'explorer-sync-viewer')
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertForbidden();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'explorer-sync-foreign-tenant')
            ->postJson("/api/tenants/beta/sites/{$betaSite->id}/sync")
            ->assertNotFound();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'explorer-sync-foreign-site')
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/sync")
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_sync_is_real_idempotent_and_exposes_authoritative_poll_status(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($tenant);
        $site = Site::query()->create(['name' => 'Explorer Site', 'url' => 'https://explorer.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $key = 'explorer-sync-55555555-5555-4555-8555-555555555555';
        $url = "/api/tenants/alpha/sites/{$site->id}/sync";

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url)
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('site_id', $site->id);

        $runId = (int) $first->json('id');
        $this->assertGreaterThan(0, $runId);
        Queue::assertPushed(SyncSiteJob::class, 1);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url)
            ->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('idempotent_replay', true);

        Queue::assertPushed(SyncSiteJob::class, 1);

        $this->activate($tenant);
        SyncRun::query()->findOrFail($runId)->update([
            'status' => 'succeeded',
            'processed' => 8,
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sync-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('site_id', $site->id)
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('processed', 8)
            ->assertJsonMissingPath('provider_secret')
            ->assertJsonMissingPath('application_password');
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');

        return $tenant;
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): void
    {
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
