<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

final class SiteConnectionCenterSynchronizeTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-3762C05261';

    public function test_source_routes_and_frontend_bind_exact_site_connection_sync_control(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}/connection"', $source);
        $this->assertStringContainsString('@page "/sites/{Id:guid}/operations"', $source);
        $this->assertStringContainsString('@onclick="SynchronizeAsync"', $source);
        $this->assertStringContainsString('_lastSync = await SyncService.SynchronizeAsync(Id);', $source);
        $this->assertStringContainsString('History.Record(_ownerUserId, Id, "synchronization"', $source);

        $component = (string) file_get_contents(resource_path('js/site-connection-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString("Idempotency-Key", $component);
        $this->assertStringContainsString("endpoints.operations", $component);

        $connection = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7/connection', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($connection->getActionName(), '\\'));
        $this->assertSame('tenant.view,sites.view', $connection->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $connection->gatherMiddleware());
        $this->assertContains('tenant.context', $connection->gatherMiddleware());

        $operations = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7/operations', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($operations->getActionName(), '\\'));
        $this->assertSame('tenant.view,sites.view', $operations->defaults['workspace_permissions'] ?? null);

        $sync = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/sync', 'POST'));
        $this->assertSame('App\\Http\\Controllers\\DemoController@sync', ltrim($sync->getActionName(), '\\'));
        $this->assertContains('auth', $sync->gatherMiddleware());
        $this->assertContains('tenant.context', $sync->gatherMiddleware());
    }

    public function test_guest_permission_and_foreign_tenant_site_fail_closed_and_authorized_sync_is_replay_safe(): void
    {
        Queue::fake();

        $viewer = User::factory()->create();
        $alpha = $this->membership($viewer, 'alpha', ['tenant.view', 'sites.view']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")->assertUnauthorized();

        $this->actingAs($viewer)
            ->withHeader('Idempotency-Key', 'connection-viewer-denied')
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertForbidden();

        $manager = User::factory()->create();
        $this->membershipExisting($manager, $alpha, ['tenant.view', 'sites.view', 'sites.manage'], 'alpha-manager');

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'connection-foreign-tenant')
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/sync")
            ->assertNotFound();

        $key = 'connection-sync-22222222-2222-4222-8222-222222222222';
        $first = $this->actingAs($manager)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');

        $runId = (int) $first->json('id');
        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('idempotent_replay', true);

        Queue::assertPushed(SyncSiteJob::class, 1);
        $this->activate($alpha);
        $this->assertSame(1, SyncRun::query()->count());
        app(TenantContext::class)->forget();
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
