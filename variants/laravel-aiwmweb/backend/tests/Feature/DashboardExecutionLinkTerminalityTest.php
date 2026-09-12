<?php

namespace Tests\Feature;

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

class DashboardExecutionLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-151800BD9D';

    public function test_exact_canonical_operation_matches_home_execution_visible_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/', $operation['route_screen']);
        $this->assertSame('/module/execution -> /module/execution', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/Home.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_destination_is_the_existing_guarded_execution_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/execution', 'GET'));

        $this->assertSame('canonical.workspace.execution', $route->getName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    public function test_execution_workspace_enforces_permission_and_tenant_isolation(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'execution.view', 'operations.manage']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['tenant.view', 'execution.view', 'operations.manage']);

        $restrictedUser = User::factory()->create();
        $this->membership($restrictedUser, 'restricted', ['tenant.view', 'execution.view']);

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/module/execution')
            ->assertOk();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/module/execution')
            ->assertNotFound();

        $this->actingAs($restrictedUser)
            ->get('/tenants/restricted/module/execution')
            ->assertForbidden();
    }

    public function test_frontend_binding_is_tenant_derived_permission_aware_and_operation_specific(): void
    {
        $appSource = (string) file_get_contents(resource_path('js/app.tsx'));
        $controlSource = (string) file_get_contents(resource_path('js/dashboard-execution-link-control.tsx'));

        $this->assertStringContainsString('route.key === \'dashboard\'', $appSource);
        $this->assertStringContainsString('DashboardExecutionLinkControl context={context}', $appSource);
        $this->assertStringContainsString(self::OPERATION_ID, $controlSource);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, \'/module/execution\')', $controlSource);
        $this->assertStringContainsString("hasPermission(context, 'operations.manage')", $controlSource);
        $this->assertStringContainsString('hasPermission(context, executionRoute.permission)', $controlSource);
        $this->assertStringNotContainsString('to="/module/execution"', $controlSource);
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
        $role = Role::query()->create([
            'name' => "dashboard-execution-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
