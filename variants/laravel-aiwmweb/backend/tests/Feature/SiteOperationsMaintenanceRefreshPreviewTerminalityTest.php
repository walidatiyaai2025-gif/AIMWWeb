<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationsMaintenanceRefreshController;
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

class SiteOperationsMaintenanceRefreshPreviewTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-C5BC29CF27';

    public function test_exact_canonical_refresh_preview_metadata_is_preserved(): void
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
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/operations/maintenance | /site-operations/maintenance', $operation['route_screen']);
        $this->assertStringContainsString('RefreshPreviewAsync', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_refresh_route_is_explicit_read_only_operation_linked_and_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/site-operations/maintenance/preview', 'GET'));

        $this->assertSame(SiteOperationsMaintenanceRefreshController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.site-operations-maintenance.preview', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('execution.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_refresh_rereads_real_tenant_scoped_storage_without_mutation(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['execution.view']);
        $this->recordOperation($alpha, 'Alpha maintenance site', 'alpha.preview');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['execution.view']);
        $this->recordOperation($beta, 'Beta maintenance site one', 'beta.preview.one');
        $this->recordOperation($beta, 'Beta maintenance site two', 'beta.preview.two');

        $response = $this->actingAs($alphaUser)->getJson('/tenants/alpha/site-operations/maintenance/preview');

        $response
            ->assertOk()
            ->assertJsonPath('data.operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.storage.record_count', 1)
            ->assertJsonPath('data.storage.site_count', 1)
            ->assertJsonPath('data.storage.storage', 'database')
            ->assertJsonPath('data.preview.total_count', 1)
            ->assertJsonPath('data.preview.keep_latest', 100);

        $this->assertDatabaseCount('site_operation_histories', 3);
    }

    public function test_guest_missing_permission_and_cross_tenant_refresh_fail_closed(): void
    {
        $this->getJson('/tenants/alpha/site-operations/maintenance/preview')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->getJson('/tenants/limited/site-operations/maintenance/preview')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['execution.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['execution.view']);

        $this->actingAs($alpha)->getJson('/tenants/beta/site-operations/maintenance/preview')->assertNotFound();
    }

    public function test_page_exposes_only_the_selected_refresh_control_and_get_contract(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view']);
        $this->withoutVite();

        $this->actingAs($user)->get('/tenants/alpha/site-operations/maintenance')
            ->assertOk()
            ->assertSee('Refresh preview')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('/tenants/alpha/site-operations/maintenance/preview', false)
            ->assertDontSee('AIMW-AI-CAAC427FC0');

        $this->actingAs($user)->postJson('/tenants/alpha/site-operations/maintenance/preview')->assertMethodNotAllowed();

        $script = (string) file_get_contents(resource_path('js/site-operations-maintenance-refresh.ts'));
        $this->assertStringContainsString(self::OPERATION_ID, $script);
        $this->assertStringContainsString("method: 'GET'", $script);
        $this->assertStringContainsString('Refreshing maintenance preview', $script);
        $this->assertStringContainsString('Maintenance preview refreshed.', $script);
        $this->assertStringContainsString('Could not refresh maintenance preview.', $script);
        $this->assertStringNotContainsString('AIMW-AI-CAAC427FC0', $script);
        $this->assertStringNotContainsString("method: 'POST'", $script);
        $this->assertStringNotContainsString("method: 'DELETE'", $script);
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
        $role = Role::query()->create(['name' => 'maintenance-refresh-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function recordOperation(Tenant $tenant, string $siteName, string $operation): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $siteName,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $siteName)).'.example.test',
            'status' => 'active',
        ]);
        app(SiteOperationHistoryService::class)->record($site->id, $operation, true, 'Completed');
        $context->forget();
    }
}
