<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

final class SiteConnectionRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-50DF6B23CC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_site_connection_route(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/connection', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_explicit_route_binds_site_and_real_diagnostics_read(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');

        $route = Route::getRoutes()->match(Request::create("/tenants/alpha/sites/{$site->id}/connection", 'GET'));
        $this->assertSame('canonical.site.connection', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($route->getActionName(), '\\'));
        $this->assertSame('sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/connection")
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}/connection")
            ->assertOk()
            ->assertJsonPath('site.id', $site->id);

        $apiRoute = Route::getRoutes()->match(Request::create("/api/tenants/alpha/sites/{$site->id}/connection", 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@status', ltrim($apiRoute->getActionName(), '\\'));
    }

    public function test_foreign_site_and_missing_permission_fail_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($user)->get("/tenants/alpha/sites/{$alphaSite->id}/connection")->assertOk();
        $this->actingAs($user)->get("/tenants/alpha/sites/{$betaSite->id}/connection")->assertNotFound();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get("/tenants/limited/sites/{$limitedSite->id}/connection")->assertForbidden();
    }

    public function test_laravel_frontend_uses_real_connection_center_states(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));

        $this->assertStringContainsString('@page "/sites/{Id:guid}/connection"', $source);
        $this->assertStringContainsString('Loading connection center', $source);
        $this->assertStringContainsString('Site not found', $source);
        $this->assertStringContainsString("r('site-connection-center', '/sites/:siteId/connection'", $core);
        $this->assertStringContainsString("route.key === 'site-connection-center'", $app);
        $this->assertStringContainsString('/connection', $control);
        $this->assertStringContainsString('OperationHistoryPayload', $control);
    }

    public function test_evidence_claims_only_this_operation(): void
    {
        $evidence = json_decode((string) file_get_contents(base_path('../docs/closure-evidence/site-connection-route-terminality.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertSame([self::OPERATION_ID], $evidence['scope_guards']['claimed_operations'] ?? null);
        $this->assertFalse((bool) ($evidence['scope_guards']['sibling_operations_claimed'] ?? true));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-connection-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test', 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
