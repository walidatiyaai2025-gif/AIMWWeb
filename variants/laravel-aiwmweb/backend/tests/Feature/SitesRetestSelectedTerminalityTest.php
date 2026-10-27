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

final class SitesRetestSelectedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-DD0FD9926B';

    public function test_source_and_laravel_control_bind_retest_selected_to_real_recheck_and_authoritative_reread(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'));
        $frontend = (string) file_get_contents(resource_path('js/sites-retest-selected-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('RetestSelectedAsync', $source);
        $this->assertStringContainsString('SiteService.RetestSitesAsync(ids)', $source);
        $this->assertStringContainsString('await ReloadAsync(false)', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('/connection/recheck', $frontend);
        $this->assertStringContainsString('query.refetch()', $frontend);
        $this->assertStringContainsString('connector.manage', $frontend);
        $this->assertStringContainsString('SitesRetestSelectedControl', $app);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection/recheck', 'POST'));
        $this->assertSame(SiteDiagnosticsController::class.'@recheck', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_foreign_tenant_and_missing_authority_fail_closed(): void
    {
        $manager = User::factory()->create();
        $this->membership($manager, 'alpha-selected-retest', ['tenant.view', 'sites.view', 'connector.manage']);

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-selected-retest', ['tenant.view', 'sites.view', 'connector.manage']);
        app(TenantContext::class)->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta Retest', 'url' => 'https://beta-retest.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)
            ->postJson("/api/tenants/alpha-selected-retest/sites/{$betaSite->id}/connection/recheck", [])
            ->assertNotFound();

        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-selected-retest', ['tenant.view', 'sites.view']);
        app(TenantContext::class)->activate($gamma);
        $gammaSite = Site::query()->create(['name' => 'Gamma Retest', 'url' => 'https://gamma-retest.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($viewer)
            ->postJson("/api/tenants/gamma-selected-retest/sites/{$gammaSite->id}/connection/recheck", [])
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-retest-selected-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }
}
