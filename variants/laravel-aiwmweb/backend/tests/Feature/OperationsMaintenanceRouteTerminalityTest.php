<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsMaintenanceReadController;
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

class OperationsMaintenanceRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-6EF2330C99';

    public function test_exact_canonical_operation_metadata_is_preserved(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/operations/maintenance', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_operation_linked_and_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/operations/maintenance', 'GET'));

        $this->assertSame(OperationsMaintenanceReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.operations-maintenance', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('execution.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_route_renders_real_tenant_scoped_storage_and_preview(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['execution.view']);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => 'Alpha operations maintenance site',
            'url' => 'https://alpha-operations-maintenance.example.test',
            'status' => 'active',
        ]);
        app(SiteOperationHistoryService::class)->record($site->id, 'alpha.sync', true, 'Alpha completed');
        $context->forget();
        $this->withoutVite();

        $response = $this->actingAs($user)->get('/tenants/alpha/operations/maintenance');

        $response
            ->assertOk()
            ->assertViewIs('operations-maintenance')
            ->assertViewHas('storage')
            ->assertViewHas('preview')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Site Operation History Maintenance')
            ->assertSee('Default retention preview');

        $storage = $response->viewData('storage');
        $preview = $response->viewData('preview');

        $this->assertIsArray($storage);
        $this->assertIsArray($preview);
        $this->assertGreaterThan(0, (int) $storage['record_count']);
        $this->assertSame('database', $storage['storage']);
        $this->assertArrayHasKey('removable_count', $preview);
        $this->assertArrayHasKey('total_count', $preview);
        $this->assertArrayHasKey('cutoff', $preview);
        $this->assertArrayHasKey('keep_latest', $preview);
        $response->assertSee('data-record-count="'.(int) $storage['record_count'].'"', false);
    }

    public function test_guest_missing_permission_and_cross_tenant_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/operations/maintenance')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->get('/tenants/limited/operations/maintenance')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['execution.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['execution.view']);

        $this->actingAs($alpha)->get('/tenants/beta/operations/maintenance')->assertNotFound();
    }

    public function test_route_does_not_expose_caller_supplied_site_or_history_ids(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/operations/maintenance', 'GET'));

        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertStringNotContainsString('{site}', $route->uri());
        $this->assertStringNotContainsString('{operation}', $route->uri());
        $this->assertStringNotContainsString('{history}', $route->uri());
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
        $role = Role::query()->create(['name' => 'Role-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
