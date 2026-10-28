<?php

namespace Tests\Feature;

use App\Http\Controllers\PlatformReadController;
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

final class DashboardRefreshClickedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-8C35E9A7A0';

    public function test_original_home_toolbar_refresh_is_wired_to_server_query_with_exact_id(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Home.razor'));
        $control = (string) file_get_contents(resource_path('js/dashboard-refresh-clicked-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="RefreshClickedAsync"', $source);
        $this->assertStringContainsString('Dashboard.GetAsync()', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('if (busy || inFlight.current) return;', $control);
        $this->assertStringContainsString('await onRefresh()', $control);
        $this->assertStringContainsString('role: \'alert\'', $control);
        $this->assertStringContainsString('DashboardRefreshClickedControl', $pages);
        $this->assertStringContainsString('onRefresh={() => query.refetch()}', $pages);
        $this->assertStringContainsString("queryKey: ['dashboard', context.tenant.slug, endpoint]", $pages);
        $this->assertStringContainsString('<LiveSystemStatusRefreshControl busy={query.isFetching} onClick={() => query.refetch()} />', $pages);

        $route = Route::getRoutes()->match(Request::create('/api/dashboard?tenant=alpha', 'GET'));
        $this->assertSame(PlatformReadController::class.'@dashboard', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    public function test_refresh_reads_current_tenant_authoritative_data_without_mutation(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-refresh', ['execution.view']);
        $this->actingAs($user)->getJson('/api/dashboard?tenant=alpha-refresh')
            ->assertOk()->assertJsonPath('sites.totalSites', 0);

        app(TenantContext::class)->activate($alpha->tenant, $alpha);
        Site::query()->create([
            'name' => 'Fresh Site', 'url' => 'https://fresh-refresh.test',
            'status' => 'active', 'connection_status' => 'connected', 'health_state' => 'healthy',
        ]);
        app(TenantContext::class)->forget();
        $before = Site::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray();

        $this->actingAs($user)->getJson('/api/dashboard?tenant=alpha-refresh')
            ->assertOk()->assertJsonPath('sites.totalSites', 1)
            ->assertJsonPath('sites.connectedSites', 1);
        $this->assertSame($before, Site::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray());
    }

    public function test_guest_missing_permission_and_foreign_tenant_read_fail_closed(): void
    {
        $owner = User::factory()->create();
        $this->membership($owner, 'alpha-refresh', ['execution.view']);
        $other = User::factory()->create();
        $this->membership($other, 'beta-refresh', ['execution.view']);
        $viewer = User::factory()->create();
        $this->membership($viewer, 'viewer-refresh', ['tenant.view']);

        $this->getJson('/api/dashboard?tenant=alpha-refresh')->assertUnauthorized();
        $this->actingAs($owner)->getJson('/api/dashboard?tenant=beta-refresh')->assertNotFound();
        $this->actingAs($viewer)->getJson('/api/dashboard?tenant=viewer-refresh')->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-dashboard-refresh-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
        return $membership->fresh('tenant');
    }
}
