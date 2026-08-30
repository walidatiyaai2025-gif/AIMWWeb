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

class ApprovalExecutionLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_operation_is_the_approval_execution_center_visible_control(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($payload['operations'])->firstWhere('operation_id', 'AIMW-APPR-B360D1C8BA');

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('approvals', $operation['domain']);
        $this->assertSame('/approvals', $operation['route_screen']);
        $this->assertSame('/module/execution -> /module/execution', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_target_is_the_existing_guarded_canonical_execution_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/execution', 'GET'));

        $this->assertSame('canonical.workspace.execution', $route->getName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    public function test_approval_route_renders_the_tenant_safe_execution_link_contract(): void
    {
        $appSource = file_get_contents(resource_path('js/app.tsx'));
        $helperSource = file_get_contents(resource_path('js/approvalQueue.ts'));

        $this->assertStringContainsString('AIMW-APPR-B360D1C8BA', $appSource);
        $this->assertStringContainsString("route.key === 'approvals'", $appSource);
        $this->assertStringContainsString('approvalExecutionCenterHref(context)', $appSource);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/execution')", $helperSource);
        $this->assertStringNotContainsString("return '/module/execution'", $helperSource);
    }

    public function test_execution_center_link_target_fails_closed_for_a_foreign_tenant(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha-execution', ['tenant.view', 'operations.manage', 'execution.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta-execution', ['tenant.view', 'operations.manage', 'execution.view']);

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha-execution/module/execution')
            ->assertOk();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta-execution/module/execution')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "approval-execution-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
