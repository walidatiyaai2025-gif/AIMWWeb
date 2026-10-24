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

final class LiveSystemStatusRefreshTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-F0C46A9BD1';

    public function test_refresh_re_reads_only_the_selected_tenants_authoritative_dashboard_without_mutation(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['execution.view']);
        $this->site($alpha, 'Alpha Site', true);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['execution.view']);
        $this->site($beta, 'Beta Site', false);

        $before = Site::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray();

        $this->actingAs($alphaUser)
            ->getJson('/api/dashboard?tenant=alpha')
            ->assertOk()
            ->assertJsonPath('sites.totalSites', 1)
            ->assertJsonPath('sites.connectedSites', 1)
            ->assertJsonPath('sites.problemSites', 0);

        $after = Site::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray();
        $this->assertSame($before, $after);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['execution.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['execution.view']);

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->getJson('/api/dashboard?tenant=alpha')->assertUnauthorized();

        $this->actingAs($limited)
            ->getJson('/api/dashboard?tenant=limited')
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/api/dashboard?tenant=beta')
            ->assertNotFound();
    }

    public function test_production_wiring_binds_the_exact_source_refresh_to_the_canonical_dashboard_read(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Shared/LiveSystemStatus.razor'));
        $control = (string) file_get_contents(resource_path('js/live-system-status-refresh-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('RefreshAsync', $source);
        $this->assertStringContainsString('DashboardService.GetAsync()', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('disabled={busy}', $control);
        $this->assertStringContainsString('if (busy) return;', $control);
        $this->assertStringContainsString('<LiveSystemStatusRefreshControl busy={query.isFetching} onRefresh={() => query.refetch()} />', $pages);

        $route = Route::getRoutes()->match(Request::create('/api/dashboard?tenant=alpha', 'GET'));
        $this->assertSame(PlatformReadController::class.'@dashboard', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
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
        $role = Role::query()->create(['name' => "live-status-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, bool $connected): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
            'connection_status' => $connected ? 'connected' : 'unreachable',
            'health_state' => $connected ? 'healthy' : 'degraded',
            'last_verified_at' => now()->subMinute(),
            'last_sync_at' => now()->subMinutes(2),
        ]);

        $context->forget();

        return $site;
    }
}
