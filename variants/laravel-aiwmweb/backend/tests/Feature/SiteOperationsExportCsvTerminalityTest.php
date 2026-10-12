<?php

namespace Tests\Feature;

use App\Http\Controllers\RouteApiAdapterController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteOperationsExportCsvTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-582319B640';

    public function test_source_frontend_and_authoritative_read_bind_the_exact_export_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor'));
        $this->assertStringContainsString('@onclick="ExportCsvAsync"', $source);
        $this->assertStringContainsString('ExportAvailabilityMessage', $source);
        $this->assertStringContainsString('CsvHeaders', $source);
        $this->assertStringContainsString('appCsv.download', $source);

        $control = (string) file_get_contents(resource_path('js/site-operations-export-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $adapter = (string) file_get_contents(app_path('Http/Controllers/RouteApiAdapterController.php'));
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('buildSiteOperationsCsv', $control);
        $this->assertStringContainsString('URL.createObjectURL', $control);
        $this->assertStringContainsString('SiteOperationsExportControl rows={visibleRows}', $pages);
        $this->assertStringContainsString('$this->siteOperations->getAll(2000)', $adapter);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/route-api/site-operations', 'GET'));
        $this->assertSame(RouteApiAdapterController::class.'@siteOperations', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authorized_reader_receives_only_active_tenant_operation_history(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-export', ['tenant.view', 'execution.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $this->operation($alpha, $alphaSite, 'alpha.sync', 'succeeded');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-export', ['tenant.view', 'execution.view']);
        $betaSite = $this->site($beta, 'Beta Site');
        $this->operation($beta, $betaSite, 'beta.sync', 'failed');

        $response = $this->actingAs($reader)
            ->getJson('/tenants/alpha-export/route-api/site-operations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation', 'alpha.sync')
            ->assertJsonPath('data.0.site.name', 'Alpha Site');

        $response->assertJsonMissing(['operation' => 'beta.sync']);
    }

    public function test_permission_and_foreign_tenant_requests_fail_closed(): void
    {
        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-export', ['tenant.view']);
        $gammaSite = $this->site($gamma, 'Gamma Site');
        $this->operation($gamma, $gammaSite, 'gamma.sync', 'succeeded');

        $this->actingAs($viewer)
            ->getJson('/tenants/gamma-export/route-api/site-operations')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreign = $this->membership($foreignOwner, 'foreign-export', ['tenant.view', 'execution.view']);
        $foreignSite = $this->site($foreign, 'Foreign Site');
        $this->operation($foreign, $foreignSite, 'foreign.sync', 'succeeded');

        $this->actingAs($viewer)
            ->getJson('/tenants/foreign-export/route-api/site-operations')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name): Site
    {
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function operation(Tenant $tenant, Site $site, string $operation, string $status): SiteOperationHistory
    {
        app(TenantContext::class)->activate($tenant);
        $row = SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'correlation_id' => (string) Str::uuid(),
            'operation' => $operation,
            'status' => $status,
            'message' => $status === 'succeeded' ? 'Completed' : 'Failed',
            'details' => ['source' => 'authoritative-test'],
            'affected_records' => 3,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
