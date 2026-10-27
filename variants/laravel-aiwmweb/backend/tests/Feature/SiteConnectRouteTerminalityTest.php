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

final class SiteConnectRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-BF5F72F4F9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_site_connect_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/connect', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOnboarding.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_route_is_explicit_read_only_permission_guarded_and_operation_linked(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/connect', 'GET'));

        $this->assertSame('canonical.site.connect', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,sites.manage', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('AIMW-SITE-E3EA44AD3F', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_route_operation_id'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->post('/tenants/alpha/sites/connect')->assertStatus(405);
    }

    public function test_authorized_route_renders_real_site_connect_workspace_contract(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/connect')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)->getJson('/tenants/alpha/context')->assertOk();
        $this->assertSame('/api/tenants/alpha/sites', $context->json('api.sites'));

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/connect')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['tenant.view', 'sites.manage']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign/sites/connect')
            ->assertNotFound();
    }

    public function test_source_and_laravel_use_real_onboarding_and_connector_contracts(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOnboarding.razor'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $provider = (string) file_get_contents(app_path('Providers/SitesConnectRouteServiceProvider.php'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('@page "/sites/connect"', $source);
        $this->assertStringContainsString('SaveTestAndSyncAsync', $source);
        $this->assertStringContainsString("r('site-connect', '/sites/connect'", $core);
        $this->assertStringContainsString("connectorScope: 'pairing'", $core);
        $this->assertStringContainsString("canonical_route_operation_id', 'AIMW-CONT-BF5F72F4F9", $provider);
        $this->assertStringContainsString("->get('/tenants/{tenant}/sites/connect'", $provider);
        $this->assertStringContainsString("Route::get('/sites', [SiteManagementController::class, 'index'])", $routes);
    }

    public function test_terminal_evidence_is_scoped_to_only_this_operation(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-connect-route-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertSame([self::OPERATION_ID], $evidence['scope_guards']['claimed_operations'] ?? null);
        $this->assertFalse((bool) ($evidence['scope_guards']['sibling_operations_claimed'] ?? true));
        $this->assertFalse((bool) ($evidence['scope_guards']['aspnet_product_modified'] ?? true));
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
        $role = Role::query()->create(['name' => "site-connect-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
