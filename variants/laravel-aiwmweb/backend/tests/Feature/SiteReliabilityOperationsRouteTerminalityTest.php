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

final class SiteReliabilityOperationsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-47007EE040';

    public function test_exact_canonical_input_is_operations_reliability_route(): void
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
        $this->assertSame('/operations/reliability', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_alias_route_is_exactly_bound_to_the_real_site_reliability_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/operations/reliability', 'GET'));

        $this->assertSame('canonical.alias.operations-reliability', $route->getName());
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirect',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/site-reliability', $route->defaults['workspace_target'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_authorized_alias_redirects_to_tenant_scoped_reliability_workspace_without_mutation(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->membership($user, 'alpha-reliability-route', ['tenant.view', 'sites.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha-reliability-route/operations/reliability')
            ->assertRedirect('/tenants/alpha-reliability-route/site-reliability');

        $this->actingAs($user)
            ->get('/tenants/alpha-reliability-route/site-reliability')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited-reliability-route', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited-reliability-route/operations/reliability')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned-reliability-route', ['tenant.view', 'sites.view']);
        Tenant::query()->create(['name' => 'Foreign Reliability Route', 'slug' => 'foreign-reliability-route']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign-reliability-route/operations/reliability')
            ->assertNotFound();
    }

    public function test_source_and_laravel_preserve_real_loading_error_and_authoritative_read_contracts(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor'),
        );
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('@page "/operations/reliability"', $source);
        $this->assertStringContainsString('@page "/site-reliability"', $source);
        $this->assertStringContainsString('if (_loading)', $source);
        $this->assertStringContainsString('else if (!string.IsNullOrWhiteSpace(_loadError))', $source);
        $this->assertStringContainsString('This is not a live-health monitor.', $source);

        $this->assertStringContainsString(
            "Route::get('/operations/reliability', 'redirect')",
            $routes,
        );
        $this->assertStringContainsString(
            "r('site-reliability', '/site-reliability'",
            $core,
        );
        $this->assertStringContainsString(
            '\'site-reliability\' => "/tenants/{$tenant}/route-api/site-reliability"',
            $routes,
        );
        $this->assertStringContainsString('if (query.isLoading) return <LoadingState />;', $pages);
        $this->assertStringContainsString('if (query.error) return (', $pages);
    }

    public function test_terminal_evidence_is_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-reliability-operations-route-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertTrue((bool) ($evidence['scope']['exactly_one_operation'] ?? false));
        $this->assertFalse((bool) ($evidence['scope']['aspnet_runtime_modified'] ?? true));
        $this->assertFalse((bool) ($evidence['scope']['generated_reconciliation_hand_edited'] ?? true));
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
        $role = Role::query()->create(['name' => "reliability-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
