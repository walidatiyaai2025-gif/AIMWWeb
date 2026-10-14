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

final class OperationsWorkspaceRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-C6C7F24B03';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_operations_workspace_route(): void
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
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/operations-workspace', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/WorkspaceHub.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_route_is_explicit_get_only_tenant_guarded_and_operation_linked(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/operations-workspace', 'GET'));

        $this->assertSame('canonical.workspace.operations-workspace', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->post('/tenants/alpha/operations-workspace')->assertStatus(405);
    }

    public function test_authorized_route_renders_real_operations_workspace_shell_and_real_destinations(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha/operations-workspace')
            ->assertOk()
            ->assertSee('id="app"', false);

        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString("r('operations-workspace', '/operations-workspace'", $core);
        $this->assertStringContainsString("{ permission: 'tenant.view', kind: 'workspace' }", $core);
        $this->assertStringContainsString("r('execution', '/module/execution'", $core);
        $this->assertStringContainsString("r('sync', '/module/sync'", $core);
        $this->assertStringContainsString("r('schedules', '/module/schedules'", $core);
        $this->assertStringContainsString("r('logs', '/module/logs'", $core);
        $this->assertStringContainsString("r('backups', '/module/backups'", $core);
        $this->assertStringContainsString("r('reports', '/module/reports'", $core);
        $this->assertStringContainsString("if (route.kind === 'workspace') return <WorkspaceHub context={context} route={route} />;", $pages);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/operations-workspace')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['sites.view']);
        $this->actingAs($limited)
            ->get('/tenants/alpha/operations-workspace')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['tenant.view']);
        $this->actingAs($authorized)
            ->get('/tenants/foreign/operations-workspace')
            ->assertNotFound();
    }

    public function test_query_identity_cannot_override_tenant_and_route_is_read_only(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/operations-workspace?tenant=foreign&user_id=999&membership_id=999')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($membershipCount, TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
        $this->assertSame($roleCount, Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
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
        $role = Role::query()->create(['name' => "operations-workspace-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
