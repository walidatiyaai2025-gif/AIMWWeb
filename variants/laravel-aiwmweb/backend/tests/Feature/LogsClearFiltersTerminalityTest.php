<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LogsClearFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-83908F2D7C';

    public function test_exact_canonical_operation_is_the_pending_logs_clear_filters_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/logs | /module/logs', $operation['route_screen']);
        $this->assertSame('ClearFilters [ClearFilters]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $operation['verification']);
    }

    public function test_logs_workspace_api_and_clear_control_are_wired_to_real_runtime(): void
    {
        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/module/logs', 'GET'));
        $api = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/logs', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($workspace->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.logs', $workspace->getName());
        $this->assertSame('operations.manage,diagnostics.view', $workspace->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $workspace->gatherMiddleware());
        $this->assertContains('tenant.context', $workspace->gatherMiddleware());

        $this->assertSame(
            AdminOperationsController::class.'@logs',
            ltrim($api->getActionName(), '\\'),
        );
        $this->assertSame(['tenant'], $workspace->parameterNames());
        $this->assertSame(['tenant'], $api->parameterNames());

        $appSource = (string) file_get_contents(resource_path('js/app.tsx'));
        $controlSource = (string) file_get_contents(resource_path('js/logs-clear-filters-control.tsx'));
        $this->assertStringContainsString("route.key === 'logs'", $appSource);
        $this->assertStringContainsString('<LogsClearFiltersControl context={context} />', $appSource);
        $this->assertStringContainsString(self::OPERATION_ID, $controlSource);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/logs')", $controlSource);
    }

    public function test_clear_path_rereads_unfiltered_persisted_tenant_logs_after_real_search_filter(): void
    {
        $user = User::factory()->create();
        $alphaMembership = $this->membership($user, 'alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);

        $this->insertLog($alphaMembership->tenant, 'alpha-needle', 'Needle event for Alpha');
        $this->insertLog($alphaMembership->tenant, 'alpha-other', 'Other Alpha event');
        $this->insertLog($betaMembership->tenant, 'beta-secret', 'Needle event for Beta');

        $this->withoutVite();
        $this->actingAs($user)
            ->get('/tenants/alpha/module/logs')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->json();
        $this->assertSame('/tenants/alpha/admin/logs', $context['api']['logs'] ?? null);

        $filtered = $this->actingAs($user)
            ->getJson('/tenants/alpha/admin/logs?search=Needle')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('Needle event for Alpha', $filtered[0]['message'] ?? null);

        $unfiltered = $this->actingAs($user)
            ->getJson('/tenants/alpha/admin/logs')
            ->assertOk()
            ->json('data');
        $messages = collect($unfiltered)->pluck('message');
        $this->assertCount(2, $unfiltered);
        $this->assertTrue($messages->contains('Needle event for Alpha'));
        $this->assertTrue($messages->contains('Other Alpha event'));
        $this->assertFalse($messages->contains('Needle event for Beta'));

        $this->actingAs($user)
            ->getJson('/tenants/alpha/admin/logs?correlation_id=beta-secret')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_guest_missing_permission_and_cross_tenant_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/module/logs')->assertRedirect('/login');
        $this->getJson('/tenants/alpha/admin/logs')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view', 'diagnostics.view']);
        $this->actingAs($limited)->get('/tenants/limited/module/logs')->assertForbidden();
        $this->actingAs($limited)->getJson('/tenants/limited/admin/logs')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);

        $this->actingAs($alpha)->get('/tenants/beta/module/logs')->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/beta/admin/logs')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "logs-clear-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function insertLog(Tenant $tenant, string $correlationId, string $message): void
    {
        DB::table('operation_logs')->insert([
            'tenant_id' => $tenant->id,
            'operation_execution_id' => null,
            'correlation_id' => $correlationId,
            'level' => 'info',
            'message' => $message,
            'context' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
