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

final class SitesSystemHealthLinkTerminalityTest extends TestCase
{
    // Exact-head CI retrigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-97AD37042A';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_source_semantics_are_exactly_the_sites_system_health_navigation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );

        $this->assertStringContainsString('<AppButton Href="/system-health"', $source);
        $this->assertStringContainsString('Text="@(L.IsArabic ? "صحة النظام" : "System health")"', $source);
    }

    public function test_laravel_binding_is_operation_specific_tenant_scoped_and_read_only(): void
    {
        $control = (string) file_get_contents(resource_path('js/sites-system-health-link-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("route.key === 'sites'", $pages);
        $this->assertStringContainsString('SitesSystemHealthLinkControl context={context}', $pages);
        $this->assertStringContainsString("systemHealthRoute.path === '/system-health'", $control);
        $this->assertStringContainsString("systemHealthRoute.permission === 'diagnostics.view'", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('diagnostics.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('*')", $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/system-health')", $control);
        $this->assertStringContainsString('context.tenants.some', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_destination_is_get_only_and_server_permission_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/system-health', 'GET'));

        $this->assertSame('tenant.system-health', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/tenants/alpha/system-health')->assertStatus(405);
    }

    public function test_authorized_member_can_open_health_and_foreign_tenant_fails_closed(): void
    {
        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha-sites-health', [
            'tenant.view',
            'sites.view',
            'diagnostics.view',
        ]);

        $this->actingAs($authorized)
            ->get('/tenants/alpha-sites-health/system-health')
            ->assertOk();

        $this->actingAs($authorized)
            ->get('/tenants/foreign-sites-health/system-health')
            ->assertNotFound();
    }

    public function test_missing_diagnostics_permission_is_forbidden(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited-sites-health', [
            'tenant.view',
            'sites.view',
        ]);

        $this->actingAs($limited)
            ->get('/tenants/limited-sites-health/system-health')
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "sites-system-health-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
