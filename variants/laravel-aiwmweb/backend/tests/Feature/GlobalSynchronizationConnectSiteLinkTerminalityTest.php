<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalSynchronizationConnectSiteLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_source_operation_and_tenant_authorized_onboarding_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-connect-site-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString('_selectedSiteId == Guid.Empty', $source);
        $this->assertStringContainsString('Href="/sites/connect"', $source);
        $this->assertStringContainsString('AIMW-SYNC-CC2B584BC4', $frontend);
        $this->assertStringContainsString('tenantUrl(slug, onboardingRoute.path)', $frontend);
        $this->assertStringContainsString('<GlobalSynchronizationConnectSiteLinkControl context={context} />', $app);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/connect', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('AIMW-SYNC-CC2B584BC4', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('tenant.view,sites.manage', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_first_time_owner_can_open_real_onboarding_even_without_a_connected_site(): void
    {
        $owner = User::factory()->create();
        $this->membership($owner, 'alpha', ['tenant.view', 'sites.manage', 'content.view', 'sync.view']);

        $this->withoutVite();
        $this->actingAs($owner)->get('/tenants/alpha/sites/connect')
            ->assertOk()->assertSee('id="app"', false);
    }

    public function test_guests_read_only_roles_and_foreign_tenants_cannot_open_onboarding(): void
    {
        $this->withoutVite();
        Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->get('/tenants/alpha/sites/connect')->assertRedirect('/login');

        $viewer = User::factory()->create();
        $this->membership($viewer, 'viewer', ['tenant.view', 'sites.view', 'sync.view', 'content.view']);
        $this->actingAs($viewer)->get('/tenants/viewer/sites/connect')->assertForbidden();

        $owner = User::factory()->create();
        $this->membership($owner, 'owned', ['tenant.view', 'sites.manage', 'sync.view', 'content.view']);
        $this->actingAs($owner)->get('/tenants/alpha/sites/connect')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'sync-connect-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
