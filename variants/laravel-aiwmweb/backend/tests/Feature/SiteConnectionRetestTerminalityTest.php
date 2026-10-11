<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteDiagnosticsController;
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

final class SiteConnectionRetestTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-E747D0E843';

    public function test_retest_contract_is_bound_to_the_real_tenant_scoped_recheck_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $this->assertStringContainsString('@onclick="RetestAsync"', $source);
        $this->assertStringContainsString('SiteService.RetestAsync(Id)', $source);

        $this->assertSame(self::OPERATION_ID, SiteDiagnosticsController::RETEST_OPERATION_ID);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection/recheck', 'POST'));
        $this->assertSame(SiteDiagnosticsController::class.'@recheck', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('canonical.api.site-connection.retest', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $frontend = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('/connection/recheck', $frontend);
        $this->assertStringContainsString('connector.manage', $frontend);
        $this->assertStringContainsString('await reload()', $frontend);
    }

    public function test_retest_fails_closed_for_foreign_tenant_and_without_mutation_authority(): void
    {
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'connector.manage']);
        $this->activate($alpha);
        Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'connector.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta Site', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/connection/recheck", [])
            ->assertNotFound();

        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma', ['tenant.view', 'sites.view']);
        $this->activate($gamma);
        $gammaSite = Site::query()->create(['name' => 'Gamma Site', 'url' => 'https://gamma.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($viewer)
            ->postJson("/api/tenants/gamma/sites/{$gammaSite->id}/connection/recheck", [])
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-retest-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
