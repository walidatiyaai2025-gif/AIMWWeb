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

final class SiteOperationsSitesLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-5BE26C7AB5';

    public function test_exact_canonical_input_is_the_pending_site_operations_sites_link(): void
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
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/site-operations | /operations/sites', $operation['route_screen']);
        $this->assertSame('/sites -> /sites', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_control_is_bound_to_the_real_tenant_scoped_sites_destination(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-operations-sites-link-control.tsx'));

        $this->assertGreaterThanOrEqual(2, substr_count($source, 'href="/sites"'));
        $this->assertStringContainsString('L.IsArabic ? "إدارة المواقع" : "Manage sites"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("workspaceRoutes.find((route) => route.key === 'sites')", $control);
        $this->assertStringContainsString("sitesRoute.path === '/sites'", $control);
        $this->assertStringContainsString("sitesRoute.permission === 'sites.view'", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString('resolveCapability(context, sitesRoute)', $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, sitesRoute.path)', $control);
        $this->assertStringContainsString('data-canonical-operation={SITE_OPERATIONS_SITES_LINK_OPERATION_ID}', $control);
        $this->assertStringContainsString("locale === 'ar' ? 'إدارة المواقع' : 'Manage sites'", $control);

        $this->assertStringContainsString(
            "import { SiteOperationsSitesLinkControl } from './site-operations-sites-link-control';",
            $pages,
        );
        $this->assertStringContainsString(
            '<SiteOperationsSitesLinkControl context={context} />',
            $pages,
        );

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('useMutation', $control);
        $this->assertStringNotContainsString('window.location', $control);
    }

    public function test_source_alias_and_destination_routes_are_explicitly_tenant_guarded(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/site-operations', 'GET'));
        $alias = Route::getRoutes()->match(Request::create('/tenants/alpha/operations/sites', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame('execution.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirect',
            ltrim($alias->getActionName(), '\\'),
        );
        $this->assertSame('execution.view', $alias->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/site-operations', $alias->defaults['workspace_target'] ?? null);
        $this->assertContains('auth', $alias->gatherMiddleware());
        $this->assertContains('tenant.context', $alias->gatherMiddleware());

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view,sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
    }

    public function test_authorized_navigation_is_real_and_permission_or_foreign_tenant_access_fails_closed(): void
    {
        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha-site-ops-link', ['tenant.view', 'execution.view', 'sites.view']);
        $this->withoutVite();

        $this->actingAs($authorized)
            ->get('/tenants/alpha-site-ops-link/site-operations')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($authorized)
            ->get('/tenants/alpha-site-ops-link/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-site-ops-link', ['tenant.view', 'execution.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited-site-ops-link/site-operations')
            ->assertOk();

        $this->actingAs($limited)
            ->get('/tenants/limited-site-ops-link/sites')
            ->assertForbidden();

        Tenant::query()->create(['name' => 'Foreign Site Ops Link', 'slug' => 'foreign-site-ops-link']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign-site-ops-link/sites')
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-operations-sites-link-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('PENDING', $evidence['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope']['only_operation_claimed'] ?? null);
        $this->assertFalse((bool) ($evidence['scope']['aspnet_modified'] ?? true));
        $this->assertFalse((bool) ($evidence['scope']['generated_reconciliation_modified'] ?? true));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-ops-link-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
