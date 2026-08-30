<?php

namespace Tests\Feature;

use App\Http\Controllers\ApprovalsReportExportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportsRouteProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'AIMW-CONT-5D18F49928' => ['canonical.workspace.reports', 'tenants/{tenant}/module/reports', '/module/reports'],
        'AIMW-CONT-8140D785B5' => ['canonical.alias.reports', 'tenants/{tenant}/reports', '/reports'],
    ];

    public function test_exact_report_route_operations_are_bound_to_the_real_guarded_controller(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rows = collect($ledger['operations'])->keyBy('operation_id');

        foreach (self::ROUTES as $operationId => [$routeName, $expectedUri, $sourceRoute]) {
            $row = $rows->get($operationId);
            $this->assertNotNull($row, "Missing canonical report route {$operationId}");
            $this->assertSame('route', $row['kind'], $operationId);
            $this->assertSame('content', $row['domain'], $operationId);
            $this->assertSame($sourceRoute, $row['route_screen'], $operationId);
            $this->assertFalse((bool) $row['mutation'], $operationId);

            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Missing Laravel route {$routeName}");
            $this->assertSame($expectedUri, $route->uri(), $operationId);
            $this->assertStringContainsString(ApprovalsReportExportController::class.'@show', $route->getActionName(), $operationId);
            $this->assertContains('auth', $route->gatherMiddleware(), $operationId);
            $this->assertContains('tenant.context', $route->gatherMiddleware(), $operationId);
        }
    }

    public function test_both_report_routes_render_real_tenant_scoped_report_state(): void
    {
        [, $member] = $this->tenantMember('alpha', ['reports.view']);

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($member->user)->get($path)
                ->assertOk()
                ->assertSee('Approvals report')
                ->assertSee('No approval rows are available for this tenant.');
        }
    }

    public function test_report_routes_fail_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        [, $alpha] = $this->tenantMember('alpha', ['reports.view']);
        $this->tenantMember('beta', ['reports.view']);
        [, $limited] = $this->tenantMember('limited', []);

        foreach (['/reports', '/module/reports'] as $suffix) {
            $this->get('/tenants/alpha'.$suffix)->assertRedirect('/login');
            $this->actingAs($limited->user)->get('/tenants/limited'.$suffix)->assertForbidden();
            $this->actingAs($alpha->user)->get('/tenants/beta'.$suffix)->assertNotFound();
        }
    }

    private function tenantMember(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-reports-route-role']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }
}
