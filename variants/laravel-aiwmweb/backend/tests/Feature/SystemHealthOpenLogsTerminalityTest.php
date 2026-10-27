<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\SystemHealthReadController;
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

class SystemHealthOpenLogsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-553D999DDB';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_system_health_open_logs_source_semantics(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/system-health', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> /module/logs', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SystemHealth.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_system_health_snapshot_is_explicit_get_only_and_permission_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/system-health', 'GET'));

        $this->assertSame('tenant.system-health.snapshot', $route->getName());
        $this->assertSame(SystemHealthReadController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/api/tenants/alpha/system-health')->assertStatus(405);
    }

    public function test_snapshot_is_real_tenant_scoped_secret_free_and_fail_closed(): void
    {
        $this->getJson('/api/tenants/alpha/system-health')->assertUnauthorized();

        $missingDiagnostics = User::factory()->create();
        $this->membership($missingDiagnostics, 'alpha', ['tenant.view']);
        $this->actingAs($missingDiagnostics)->getJson('/api/tenants/alpha/system-health')->assertForbidden();

        $authorized = User::factory()->create();
        $membership = $this->membership($authorized, 'owned', ['tenant.view', 'diagnostics.view']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $secretSentinel = 'system-health-open-logs-secret-must-never-render';
        config()->set('services.test_only.secret', $secretSentinel);

        $response = $this->actingAs($authorized)->getJson(
            '/api/tenants/owned/system-health?tenant=foreign&user_id=999&site=999',
        );

        $response->assertOk()
            ->assertJsonPath('tenant', 'owned')
            ->assertJsonPath('checks.app.status', 'ok')
            ->assertDontSee($secretSentinel)
            ->assertDontSee('user_id')
            ->assertDontSee('site=999');

        $this->actingAs($authorized)->getJson('/api/tenants/foreign/system-health')->assertNotFound();
        $this->assertSame(
            $membershipCount,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame(
            $roleCount,
            Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
    }

    public function test_logs_destination_keeps_server_side_rbac_and_foreign_tenant_idor_protection(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/logs', 'GET'));

        $this->assertSame('canonical.workspace.logs', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('operations.manage,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $healthOnly = User::factory()->create();
        $this->membership($healthOnly, 'alpha', ['tenant.view', 'diagnostics.view']);
        $this->actingAs($healthOnly)->get('/tenants/alpha/module/logs')->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['tenant.view', 'diagnostics.view', 'operations.manage']);
        $this->actingAs($authorized)->get('/tenants/owned/module/logs')->assertOk();
        $this->actingAs($authorized)->get('/tenants/foreign/module/logs')->assertNotFound();
        $this->actingAs($authorized)->post('/tenants/owned/module/logs')->assertStatus(405);
    }

    public function test_production_binding_requires_successful_snapshot_and_exact_operation_marker(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $workspace = (string) file_get_contents(resource_path('js/system-health-workspace.tsx'));
        $control = (string) file_get_contents(resource_path('js/system-health-open-logs-control.tsx'));

        $this->assertStringContainsString("route.key === 'system-health'", $app);
        $this->assertStringContainsString('SystemHealthWorkspace', $app);
        $this->assertStringContainsString('/api/tenants/${encodeURIComponent(context.tenant.slug)}/system-health', $workspace);
        $this->assertStringContainsString('if (!snapshot || snapshot.tenant !== context.tenant.slug)', $workspace);
        $this->assertStringContainsString('snapshotReady={true}', $workspace);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("logsRoute.path === '/module/logs'", $control);
        $this->assertStringContainsString('context.api.logs === expectedLogsApi', $control);
        $this->assertStringContainsString("context.permissions.includes('operations.manage')", $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('method:', $control);
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
        $role = Role::query()->create(['name' => "system-health-open-logs-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
