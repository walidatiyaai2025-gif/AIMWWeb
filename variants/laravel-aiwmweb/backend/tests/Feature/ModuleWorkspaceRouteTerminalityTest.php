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

final class ModuleWorkspaceRouteTerminalityTest extends TestCase
{
    // Exact-head CI marker after the final materialized reconciliation.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-80EE7CA296';

    public function test_exact_canonical_input_is_generic_module_workspace_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/module/{Key}', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_generic_module_route_is_explicitly_bound_to_real_app_and_operation_id(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/future-capability', 'GET'));

        $this->assertSame('canonical.workspace.module-fallback', $route->getName());
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_authorized_generic_route_renders_the_tenant_app_without_mutation(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->membership($user, 'alpha-module-route', ['tenant.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha-module-route/module/future-capability')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited-module-route', []);

        $this->actingAs($limited)
            ->get('/tenants/limited-module-route/module/future-capability')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned-module-route', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign Module Route', 'slug' => 'foreign-module-route']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign-module-route/module/future-capability')
            ->assertNotFound();
    }

    public function test_static_module_routes_keep_their_stricter_contracts_ahead_of_fallback(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/logs', 'GET'));

        $this->assertSame('canonical.workspace.logs', $route->getName());
        $this->assertSame('operations.manage,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
    }

    public function test_source_and_laravel_render_unknown_modules_without_fake_results_or_actions(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor'),
        );
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $fallback = (string) file_get_contents(resource_path('js/module-workspace-route-fallback.tsx'));
        $unavailableControl = (string) file_get_contents(resource_path('js/module-workspace-back-to-home-control.tsx'));

        $this->assertStringContainsString('@page "/module/{Key}"', $source);
        $this->assertStringContainsString('data-testid="module-workspace-fallback"', $source);
        $this->assertStringContainsString('data-testid="module-unavailable"', $source);
        $this->assertStringContainsString('AIMWWeb will not present fake results or actions', $source);

        $this->assertStringContainsString("Route::get('/module/{key}', 'show')", $routes);
        $this->assertStringContainsString(self::OPERATION_ID, $routes);
        $this->assertStringContainsString('<Route path="module/:moduleKey" element={<ModuleWorkspaceDynamicRoute />} />', $app);
        $this->assertStringContainsString('candidate.path === `/module/${normalizedKey}`', $app);
        $this->assertStringContainsString('<ModuleWorkspaceFallbackRoute />', $app);
        $this->assertStringContainsString('ModuleWorkspaceBackToHomeControl', $app);
        $this->assertStringContainsString('children ?? <ModuleWorkspaceBackToHomeControl context={context} />', $fallback);
        $this->assertStringContainsString('data-testid="module-unavailable"', $unavailableControl);
        $this->assertStringContainsString('will not present fake results or actions', $unavailableControl);
    }

    public function test_terminal_evidence_is_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/module-workspace-route-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertTrue((bool) ($evidence['scope']['exactly_one_operation'] ?? false));
        $this->assertFalse((bool) ($evidence['scope']['aspnet_runtime_modified'] ?? true));
        $this->assertFalse((bool) ($evidence['scope']['generated_reconciliation_hand_edited'] ?? true));
        $this->assertFalse((bool) ($evidence['scope']['sibling_operation_claimed'] ?? true));
    }

    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "module-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
