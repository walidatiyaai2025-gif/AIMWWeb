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
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteOperationsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-55FA799054';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_site_operations_route(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/operations', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_explicit_route_binds_site_and_reads_authoritative_operation_history(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');

        $route = Route::getRoutes()->match(Request::create("/tenants/alpha/sites/{$site->id}/operations", 'GET'));
        $this->assertSame('canonical.site.operations', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($route->getActionName(), '\\'));
        $this->assertSame('sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/operations")
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}/operations?take=100")
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        app(SiteOperationHistoryService::class)->record(
            $site->id,
            'synchronization',
            true,
            'WordPress synchronization completed.',
            ['provider_secret' => 'must-not-leak'],
            4,
        );
        $context->forget();

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}/operations?take=100")
            ->assertOk()
            ->assertJsonPath('items.0.operation', 'synchronization')
            ->assertJsonPath('items.0.status', 'succeeded')
            ->assertJsonPath('items.0.message', 'WordPress synchronization completed.')
            ->assertJsonPath('items.0.affected_records', 4)
            ->assertJsonPath('items.0.details.provider_secret', '[REDACTED]');

        $apiRoute = Route::getRoutes()->match(Request::create("/api/tenants/alpha/sites/{$site->id}/operations", 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@operations', ltrim($apiRoute->getActionName(), '\\'));
        $this->assertContains('auth', $apiRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $apiRoute->gatherMiddleware());
    }

    public function test_foreign_site_and_missing_permission_fail_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($user)->get("/tenants/alpha/sites/{$alphaSite->id}/operations")->assertOk();
        $this->actingAs($user)->get("/tenants/alpha/sites/{$betaSite->id}/operations")->assertNotFound();
        $this->actingAs($user)->getJson("/api/tenants/alpha/sites/{$betaSite->id}/operations")->assertNotFound();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');

        $this->actingAs($limited)
            ->get("/tenants/limited/sites/{$limitedSite->id}/operations")
            ->assertForbidden();
    }

    public function test_frontend_alias_uses_real_loading_empty_error_and_history_wiring(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));

        $this->assertStringContainsString('@page "/sites/{Id:guid}/operations"', $source);
        $this->assertStringContainsString('Loading connection center', $source);
        $this->assertStringContainsString('Site not found', $source);
        $this->assertStringContainsString('No operations recorded yet.', $source);
        $this->assertStringContainsString("r('site-connection-operations', '/sites/:siteId/operations'", $core);
        $this->assertStringContainsString("route.key === 'site-connection-center' || route.key === 'site-connection-operations'", $app);
        $this->assertStringContainsString('/operations?take=100', $control);
        $this->assertStringContainsString('apiRequest<OperationHistoryPayload>(endpoints.operations)', $control);
        $this->assertStringContainsString('setHistory(Array.isArray(nextOperations.items) ? nextOperations.items : [])', $control);
        $this->assertStringContainsString('setError(cause instanceof Error ? cause.message', $control);
    }

    public function test_evidence_claims_only_this_operation(): void
    {
        $evidence = json_decode((string) file_get_contents(base_path('../docs/closure-evidence/site-operations-route-terminality.json')), true, 512, JSON_THROW_ON_ERROR);

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
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-operations-{$slug}-{$user->id}"]);

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
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
