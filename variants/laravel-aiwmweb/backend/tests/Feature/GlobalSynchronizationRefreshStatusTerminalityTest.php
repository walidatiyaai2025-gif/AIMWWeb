<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentExplorerReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalSynchronizationRefreshStatusTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_contract_frontend_and_read_only_tenant_bound_routes(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-refresh-status-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('OnClick="RefreshStatusAsync"', $source);
        $this->assertStringContainsString('private async Task RefreshStatusAsync', $source);
        $this->assertStringContainsString('SyncService.GetExplorerAsync(_selectedSiteId)', $source);
        $this->assertStringContainsString('SyncService.GetHistoryAsync(_selectedSiteId, 15)', $source);
        $this->assertStringContainsString('AIMW-SYNC-9504A57CAE', $frontend);
        $this->assertStringContainsString('GlobalSynchronizationRefreshStatusControl', $app);

        $history = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/sync', 'GET'));
        $this->assertSame('App\\Http\\Controllers\\SyncApiController@index', ltrim($history->getActionName(), '\\'));
        $this->assertContains('tenant.context', $history->gatherMiddleware());
        $explorer = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/explorer', 'GET'));
        $this->assertSame(ContentExplorerReadController::class, ltrim($explorer->getActionName(), '\\'));
        $this->assertContains('auth', $explorer->gatherMiddleware());
        $this->assertContains('tenant.context', $explorer->gatherMiddleware());
    }

    public function test_refresh_reads_authoritative_local_mirror_and_sync_runs_without_mutation(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'content.view', 'sync.view']);
        $site = $this->site($tenant, 'Owned Site');

        $this->actingAs($owner)->getJson("/api/v1/tenants/alpha/sites/{$site->id}/sync")
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');

        $this->actingAs($owner)->getJson("/api/v1/tenants/alpha/sites/{$site->id}/explorer")
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('meta.site.id', $site->id);
    }

    public function test_guest_missing_permission_and_foreign_tenant_site_fail_closed(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'content.view', 'sync.view']);
        $alphaSite = $this->site($alpha, 'Owned Site');
        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'content.view', 'sync.view']);
        $betaSite = $this->site($beta, 'Foreign Site');

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha, ['tenant.view', 'sites.view', 'sync.view']);

        $this->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/explorer")->assertUnauthorized();
        $this->actingAs($viewer)->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync")->assertForbidden();

        $this->actingAs($owner)->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/sync")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/explorer")->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions);

        return $tenant;
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'sync-viewer-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }

    private function site(Tenant $tenant, string $name): Site
    {
        app(TenantContext::class)->activate($tenant);
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
            ]);
        } finally {
            app(TenantContext::class)->forget();
        }
    }
}
