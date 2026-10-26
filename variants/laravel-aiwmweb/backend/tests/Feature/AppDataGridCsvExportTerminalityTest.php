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

final class AppDataGridCsvExportTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-EB9202408A';

    public function test_source_and_laravel_bind_exact_csv_export_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor'));
        $helper = (string) file_get_contents(resource_path('js/app-data-grid-csv-export-control.ts'));
        $consumer = (string) file_get_contents(resource_path('js/site-operations-export-control.tsx'));

        $this->assertStringContainsString('if (CsvRowSelector is not null)', $source);
        $this->assertStringContainsString('OnClick="ExportCsvAsync"', $source);
        $this->assertStringContainsString('SortedItems.Select(CsvRowSelector).ToList()', $source);
        $this->assertStringContainsString('appCsv.download', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $helper);
        $this->assertStringContainsString('@ExportText [ExportCsvAsync]', $helper);
        $this->assertStringContainsString('rows.map((row) => [...rowSelector(row)])', $helper);
        $this->assertStringContainsString('requestAppDataGridCsvExport(', $consumer);
        $this->assertStringContainsString('data-canonical-app-data-grid-csv-operation', $consumer);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/route-api/site-operations', 'GET'));
        $this->assertSame(RouteApiAdapterController::class.'@siteOperations', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_export_rows_are_authoritative_and_tenant_scoped(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-csv-grid', ['tenant.view', 'execution.view']);
        $alphaSite = $this->site($alpha, 'Alpha CSV Grid');
        $this->operation($alpha, $alphaSite, 'alpha.csv.grid');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-csv-grid', ['tenant.view', 'execution.view']);
        $betaSite = $this->site($beta, 'Beta CSV Grid');
        $this->operation($beta, $betaSite, 'beta.csv.grid');

        $response = $this->actingAs($reader)
            ->getJson('/tenants/alpha-csv-grid/route-api/site-operations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation', 'alpha.csv.grid');

        $response->assertJsonMissing(['operation' => 'beta.csv.grid']);
        $this->actingAs($reader)->getJson('/tenants/beta-csv-grid/route-api/site-operations')->assertNotFound();
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

    private function operation(Tenant $tenant, Site $site, string $operation): SiteOperationHistory
    {
        app(TenantContext::class)->activate($tenant);
        $row = SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'correlation_id' => (string) Str::uuid(),
            'operation' => $operation,
            'status' => 'succeeded',
            'message' => 'Completed',
            'details' => ['source' => 'authoritative-grid-csv-test'],
            'affected_records' => 2,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
