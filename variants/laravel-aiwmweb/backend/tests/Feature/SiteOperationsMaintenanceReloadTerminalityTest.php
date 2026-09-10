<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationsMaintenanceReloadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteOperationsMaintenanceReloadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-CAAC427FC0';

    public function test_exact_canonical_reload_metadata_is_preserved(): void
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
        $this->assertStringContainsString('ReloadAsync', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_reload_route_is_explicit_read_only_operation_linked_and_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/site-operations/maintenance/reload', 'GET'));

        $this->assertSame(SiteOperationsMaintenanceReloadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.site-operations-maintenance.reload', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('execution.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_reload_rereads_real_tenant_scoped_snapshot_with_selected_policy_without_mutation(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['execution.view']);
        $this->recordOperation($alpha, 'Alpha maintenance site', 'alpha.reload');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['execution.view']);
        $this->recordOperation($beta, 'Beta maintenance site one', 'beta.reload.one');
        $this->recordOperation($beta, 'Beta maintenance site two', 'beta.reload.two');

        $before = now();
        $response = $this->actingAs($alphaUser)->getJson(
            '/tenants/alpha/site-operations/maintenance/reload?older_than_days=180&keep_latest=250',
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.policy.older_than_days', 180)
            ->assertJsonPath('data.policy.keep_latest', 250)
            ->assertJsonPath('data.storage.record_count', 1)
            ->assertJsonPath('data.storage.site_count', 1)
            ->assertJsonPath('data.storage.storage', 'database')
            ->assertJsonPath('data.preview.total_count', 1)
            ->assertJsonPath('data.preview.keep_latest', 250);

        $cutoff = CarbonImmutable::parse((string) $response->json('data.preview.cutoff'));
        $this->assertTrue(
            $cutoff->betweenIncluded(
                $before->copy()->subDays(180)->subSecond(),
                now()->subDays(180)->addSecond(),
            ),
        );
        $this->assertDatabaseCount('site_operation_histories', 3);
    }

    public function test_reload_policy_rejects_values_not_supported_by_the_authoritative_source(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view']);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/site-operations/maintenance/reload?older_than_days=31&keep_latest=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['older_than_days']);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/site-operations/maintenance/reload?older_than_days=90&keep_latest=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keep_latest']);

        $this->assertDatabaseCount('site_operation_histories', 0);
    }

    public function test_guest_missing_permission_and_cross_tenant_reload_fail_closed(): void
    {
        $this->getJson('/tenants/alpha/site-operations/maintenance/reload')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->getJson('/tenants/limited/site-operations/maintenance/reload')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['execution.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['execution.view']);

        $this->actingAs($alpha)->getJson('/tenants/beta/site-operations/maintenance/reload')->assertNotFound();
    }

    public function test_page_and_frontend_bind_reload_separately_from_refresh_preview(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view']);
        $this->withoutVite();

        $this->actingAs($user)->get('/tenants/alpha/site-operations/maintenance')
            ->assertOk()
            ->assertSee('↻ Refresh')
            ->assertSee('data-maintenance-reload', false)
            ->assertSee('data-maintenance-refresh-control', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('/tenants/alpha/site-operations/maintenance/reload', false)
            ->assertSee('Refresh preview')
            ->assertSee('data-canonical-operation="AIMW-AI-C5BC29CF27"', false);

        $this->actingAs($user)->postJson('/tenants/alpha/site-operations/maintenance/reload')->assertMethodNotAllowed();

        $reloadScript = (string) file_get_contents(resource_path('js/site-operations-maintenance-reload.ts'));
        $this->assertStringContainsString(self::OPERATION_ID, $reloadScript);
        $this->assertStringContainsString('refreshMaintenanceSnapshot', $reloadScript);
        $this->assertStringContainsString('Refreshing maintenance data', $reloadScript);
        $this->assertStringContainsString('Maintenance data refreshed.', $reloadScript);
        $this->assertStringContainsString('Could not refresh maintenance data.', $reloadScript);
        $this->assertStringNotContainsString("method: 'POST'", $reloadScript);
        $this->assertStringNotContainsString("method: 'DELETE'", $reloadScript);

        $sharedScript = (string) file_get_contents(resource_path('js/site-operations-maintenance-refresh.ts'));
        $this->assertStringContainsString('data-maintenance-refresh-control', $sharedScript);
        $this->assertStringContainsString('payload.data?.operation_id !== expectedOperationId', $sharedScript);
        $this->assertStringContainsString("method: 'GET'", $sharedScript);
        $this->assertStringContainsString('Cross-origin maintenance refresh endpoint rejected', $sharedScript);
        $this->assertStringNotContainsString(self::OPERATION_ID, $sharedScript);
        $this->assertStringNotContainsString("method: 'POST'", $sharedScript);
        $this->assertStringNotContainsString("method: 'DELETE'", $sharedScript);
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
        $role = Role::query()->create(['name' => 'maintenance-reload-'.$slug.'-'.$user->id]);
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
