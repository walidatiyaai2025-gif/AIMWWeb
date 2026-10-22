<?php

namespace Tests\Feature;

use App\Http\Controllers\RouteApiAdapterController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteOperationsReloadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-B84663A4C0';

    public function test_reload_async_is_bound_to_the_real_tenant_scoped_authoritative_read(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-operations-reload-control.ts'));

        $this->assertStringContainsString('private async Task ReloadAsync()', $source);
        $this->assertStringContainsString('History.GetAll', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('runAuthoritativeSiteOperationsReload', $pages);
        $this->assertStringContainsString('SITE_OPERATIONS_RELOAD_OPERATION_ID', $pages);

        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-reload', ['tenant.view', 'execution.view']);
        $alphaSite = $this->site($alpha, 'Alpha Reload Site');
        $this->operation($alpha, $alphaSite, 'alpha.reload', 'succeeded');

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-reload', ['tenant.view', 'execution.view']);
        $betaSite = $this->site($beta, 'Beta Reload Site');
        $this->operation($beta, $betaSite, 'beta.reload', 'failed');

        $before = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($reader)
            ->getJson('/tenants/alpha-reload/route-api/site-operations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation', 'alpha.reload')
            ->assertJsonPath('data.0.site.name', 'Alpha Reload Site');

        $response->assertJsonMissing(['operation' => 'beta.reload']);
        $this->assertSame($before, SiteOperationHistory::query()->withoutGlobalScopes()->count());

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha-reload/route-api/site-operations', 'GET'));
        $this->assertSame(RouteApiAdapterController::class.'@siteOperations', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_reload_async_permission_and_foreign_tenant_requests_fail_closed(): void
    {
        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-reload', ['tenant.view']);
        $gammaSite = $this->site($gamma, 'Gamma Reload Site');
        $this->operation($gamma, $gammaSite, 'gamma.reload', 'succeeded');

        $this->actingAs($viewer)
            ->getJson('/tenants/gamma-reload/route-api/site-operations')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreign = $this->membership($foreignOwner, 'foreign-reload', ['tenant.view', 'execution.view']);
        $foreignSite = $this->site($foreign, 'Foreign Reload Site');
        $this->operation($foreign, $foreignSite, 'foreign.reload', 'succeeded');

        $this->actingAs($viewer)
            ->getJson('/tenants/foreign-reload/route-api/site-operations')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name): Site
    {
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function operation(Tenant $tenant, Site $site, string $operation, string $status): SiteOperationHistory
    {
        app(TenantContext::class)->activate($tenant);
        $row = SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'correlation_id' => (string) Str::uuid(),
            'operation' => $operation,
            'status' => $status,
            'message' => $status === 'succeeded' ? 'Completed' : 'Failed',
            'details' => ['source' => 'reload-authoritative-test'],
            'affected_records' => 3,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
