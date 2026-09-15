<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsMaintenanceReadController;
use App\Http\Controllers\SiteOperationsMaintenanceReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationsMaintenanceOperationsHubTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-9E73ABE9CE';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_canonical_operation_is_the_operations_hub_visible_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/operations/maintenance | /site-operations/maintenance', $operation['route_screen']);
        $this->assertSame('/operations -> /operations', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(self::OPERATION_ID, OperationsMaintenanceReadController::OPERATIONS_HUB_OPERATION_ID);
        $this->assertSame(self::OPERATION_ID, SiteOperationsMaintenanceReadController::OPERATIONS_HUB_OPERATION_ID);
    }

    public function test_both_real_maintenance_aliases_render_the_tenant_bound_operations_hub_link(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view', 'operations.manage']);
        $expectedHref = '/tenants/alpha/operations';

        foreach (['operations/maintenance', 'site-operations/maintenance'] as $source) {
            $response = $this->actingAs($user)->get("/tenants/alpha/{$source}");

            $response->assertOk()
                ->assertSee('Operations hub')
                ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
                ->assertSee('href="'.$expectedHref.'"', false);
        }

        $target = Route::getRoutes()->getByName('canonical.workspace.operations');
        $this->assertNotNull($target);
        $this->assertSame('tenants/{tenant}/operations', $target->uri());
        $this->assertSame('operations.manage,execution.view', $target->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $target->gatherMiddleware());
        $this->assertContains('tenant.context', $target->gatherMiddleware());

        $this->actingAs($user)->get($expectedHref)->assertOk();
    }

    public function test_control_is_hidden_when_target_permission_is_missing_and_target_remains_forbidden(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view']);

        foreach (['operations/maintenance', 'site-operations/maintenance'] as $source) {
            $this->actingAs($user)
                ->get("/tenants/alpha/{$source}")
                ->assertOk()
                ->assertDontSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
                ->assertDontSee('Operations hub');
        }

        $this->actingAs($user)->get('/tenants/alpha/operations')->assertForbidden();
    }

    public function test_navigation_is_read_only_and_cross_tenant_paths_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['execution.view', 'operations.manage']);
        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['execution.view', 'operations.manage']);

        $historyBefore = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/operations/maintenance')
            ->assertOk();
        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/site-operations/maintenance')
            ->assertOk();
        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/operations')
            ->assertOk();

        $this->assertSame(
            $historyBefore,
            SiteOperationHistory::query()->withoutGlobalScopes()->count(),
        );

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/operations/maintenance')
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get('/tenants/beta/site-operations/maintenance')
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get('/tenants/beta/operations')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'maintenance-hub-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
