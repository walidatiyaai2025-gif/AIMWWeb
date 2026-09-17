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

final class LogsCopyVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-2138CD95B2';

    public function test_exact_canonical_operation_is_the_adapted_logs_copy_visible_control(): void
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
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/logs | /module/logs', $operation['route_screen']);
        $this->assertSame('CopyVisibleAsync [CopyVisibleAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_copy_control_is_bound_to_the_real_guarded_tenant_logs_read_path(): void
    {
        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/module/logs', 'GET'));
        $api = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/logs', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($workspace->getActionName(), '\\'),
        );
        $this->assertSame('operations.manage,diagnostics.view', $workspace->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $workspace->gatherMiddleware());
        $this->assertContains('tenant.context', $workspace->gatherMiddleware());
        $this->assertSame(['tenant'], $workspace->parameterNames());

        $this->assertSame(
            AdminOperationsController::class.'@logs',
            ltrim($api->getActionName(), '\\'),
        );
        $this->assertSame(['tenant'], $api->parameterNames());

        $control = (string) file_get_contents(resource_path('js/logs-clear-filters-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, \'/admin/logs\')', $control);
        $this->assertStringContainsString('context.api.logs === expectedLogsApi', $control);
        $this->assertStringContainsString('context.permissions.includes(\'operations.manage\')', $control);
        $this->assertStringContainsString('context.permissions.includes(\'diagnostics.view\')', $control);
        $this->assertStringContainsString('apiRequest<LogsPayload>', $control);
        $this->assertStringContainsString('navigator.clipboard', $control);
        $this->assertStringContainsString('await clipboard.writeText(formatVisibleLogs(rows))', $control);
    }

    public function test_authoritative_search_read_is_tenant_scoped_and_foreign_rows_do_not_leak(): void
    {
        $user = User::factory()->create();
        $alphaMembership = $this->membership($user, 'alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);

        $this->insertLog($alphaMembership->tenant, 'alpha-needle', 'Needle event for Alpha', 'Information');
        $this->insertLog($alphaMembership->tenant, 'alpha-other', 'Other Alpha event', 'Warning');
        $this->insertLog($betaMembership->tenant, 'beta-secret', 'Needle event for Beta', 'Critical');

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
        $this->assertSame('Information', $filtered[0]['level'] ?? null);
        $this->assertFalse(collect($filtered)->pluck('message')->contains('Needle event for Beta'));
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
        $role = Role::query()->create(['name' => "logs-copy-visible-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function insertLog(Tenant $tenant, string $correlationId, string $message, string $level): void
    {
        DB::table('operation_logs')->insert([
            'tenant_id' => $tenant->id,
            'operation_execution_id' => null,
            'correlation_id' => $correlationId,
            'level' => $level,
            'message' => $message,
            'context' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
