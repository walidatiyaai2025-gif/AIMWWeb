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

final class AppDataGridExportRequestedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-C18ED1F753';

    public function test_source_and_laravel_bind_exact_export_requested_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor'),
        );
        $helper = (string) file_get_contents(resource_path('js/app-data-grid-export-requested-control.ts'));
        $consumer = (string) file_get_contents(resource_path('js/site-operations-export-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('else if (ExportRequested.HasDelegate)', $source);
        $this->assertStringContainsString('Text="@ExportText"', $source);
        $this->assertStringContainsString('OnClick="ExportAsync"', $source);
        $this->assertStringContainsString(
            'ExportRequested.InvokeAsync(SortedItems.ToList())',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $helper);
        $this->assertStringContainsString('onExportRequested([...rows])', $helper);
        $this->assertStringContainsString('requestAppDataGridExport(rows', $consumer);
        $this->assertStringContainsString('data-canonical-export-requested-operation', $consumer);
        $this->assertStringContainsString('SiteOperationsExportControl rows={visibleRows}', $pages);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/route-api/site-operations', 'GET'),
        );
        $this->assertSame(
            RouteApiAdapterController::class.'@siteOperations',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_export_consumer_reads_only_authoritative_active_tenant_rows(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-grid-export', ['tenant.view', 'execution.view']);
        $alphaSite = $this->site($alpha, 'Alpha Grid Export');
        $this->operation($alpha, $alphaSite, 'alpha.grid.export', 'succeeded');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-grid-export', ['tenant.view', 'execution.view']);
        $betaSite = $this->site($beta, 'Beta Grid Export');
        $this->operation($beta, $betaSite, 'beta.grid.export', 'failed');

        $response = $this->actingAs($reader)
            ->getJson('/tenants/alpha-grid-export/route-api/site-operations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation', 'alpha.grid.export');

        $response->assertJsonMissing(['operation' => 'beta.grid.export']);
    }

    public function test_permission_and_foreign_tenant_reads_fail_closed(): void
    {
        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-grid-export', ['tenant.view']);
        $gammaSite = $this->site($gamma, 'Gamma Grid Export');
        $this->operation($gamma, $gammaSite, 'gamma.grid.export', 'succeeded');

        $this->actingAs($viewer)
            ->getJson('/tenants/gamma-grid-export/route-api/site-operations')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreign = $this->membership($foreignOwner, 'foreign-grid-export', ['tenant.view', 'execution.view']);
        $foreignSite = $this->site($foreign, 'Foreign Grid Export');
        $this->operation($foreign, $foreignSite, 'foreign.grid.export', 'succeeded');

        $this->actingAs($viewer)
            ->getJson('/tenants/foreign-grid-export/route-api/site-operations')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
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
            'details' => ['source' => 'authoritative-grid-export-test'],
            'affected_records' => 2,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
