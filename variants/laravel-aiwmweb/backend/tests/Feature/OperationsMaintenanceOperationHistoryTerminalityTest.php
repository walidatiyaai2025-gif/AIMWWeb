<?php

namespace Tests\Feature;

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

class OperationsMaintenanceOperationHistoryTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-C2776A0F99';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_canonical_operation_is_the_read_only_operation_history_visible_control(): void
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
        $this->assertSame('/site-operations -> /site-operations', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_both_real_maintenance_aliases_render_the_tenant_bound_operation_history_link(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view']);
        $expectedHref = '/tenants/alpha/site-operations';

        foreach (['operations/maintenance', 'site-operations/maintenance'] as $source) {
            $this->actingAs($user)
                ->get("/tenants/alpha/{$source}")
                ->assertOk()
                ->assertSee('Operation history')
                ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
                ->assertSee('href="'.$expectedHref.'"', false);
        }

        $target = Route::getRoutes()->getByName('canonical.workspace.site-operations');
        $this->assertNotNull($target);
        $this->assertSame('tenants/{tenant}/site-operations', $target->uri());
        $this->assertSame('execution.view', $target->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $target->gatherMiddleware());
        $this->assertContains('tenant.context', $target->gatherMiddleware());

        $this->actingAs($user)->get($expectedHref)->assertOk();
    }

    public function test_source_and_target_share_the_execution_view_boundary(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['tenant.view']);

        $this->get('/tenants/alpha/site-operations/maintenance')->assertRedirect('/login');
        $this->get('/tenants/alpha/operations/maintenance')->assertRedirect('/login');
        $this->get('/tenants/alpha/site-operations')->assertRedirect('/login');

        $this->actingAs($limited)->get('/tenants/alpha/site-operations/maintenance')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/alpha/operations/maintenance')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/alpha/site-operations')->assertForbidden();
    }

    public function test_navigation_is_read_only_and_cross_tenant_paths_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['execution.view']);
        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['execution.view']);

        $historyBefore = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $this->actingAs($alphaUser)->get('/tenants/alpha/operations/maintenance')->assertOk();
        $this->actingAs($alphaUser)->get('/tenants/alpha/site-operations/maintenance')->assertOk();
        $this->actingAs($alphaUser)->get('/tenants/alpha/site-operations')->assertOk();

        $this->assertSame(
            $historyBefore,
            SiteOperationHistory::query()->withoutGlobalScopes()->count(),
        );

        $this->actingAs($alphaUser)->get('/tenants/beta/operations/maintenance')->assertNotFound();
        $this->actingAs($alphaUser)->get('/tenants/beta/site-operations/maintenance')->assertNotFound();
        $this->actingAs($alphaUser)->get('/tenants/beta/site-operations')->assertNotFound();
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
        $role = Role::query()->create(['name' => 'maintenance-history-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
