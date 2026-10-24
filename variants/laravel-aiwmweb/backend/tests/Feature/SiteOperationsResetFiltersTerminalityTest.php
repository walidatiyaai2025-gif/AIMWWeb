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

final class SiteOperationsResetFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-915652366F';

    public function test_source_semantics_are_exactly_reset_filters_and_defaults(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor'),
        );

        $this->assertStringContainsString('@onclick="ResetFilters"', $source);
        $this->assertStringContainsString('Reset filters', $source);
        $this->assertStringContainsString('private void ResetFilters()', $source);
        $this->assertStringContainsString('_query = string.Empty;', $source);
        $this->assertStringContainsString('_siteFilter = string.Empty;', $source);
        $this->assertStringContainsString('_statusFilter = "all";', $source);
        $this->assertStringContainsString('_fromDate = DateTime.Today.AddDays(-30);', $source);
        $this->assertStringContainsString('_toDate = DateTime.Today;', $source);
        $this->assertStringContainsString('_exportError = null;', $source);
    }

    public function test_laravel_runtime_binds_filtering_reset_and_visible_row_export_without_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/site-operations-filters-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $export = (string) file_get_contents(resource_path('js/site-operations-export-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('siteOperationsDefaultFilters', $control);
        $this->assertStringContainsString('filterSiteOperationsRows', $control);
        $this->assertStringContainsString("status !== 'succeeded'", $control);
        $this->assertStringContainsString('The start date cannot be after the end date.', $control);

        $this->assertStringContainsString('<SiteOperationsFiltersControl', $pages);
        $this->assertStringContainsString("setSearchInput('')", $pages);
        $this->assertStringContainsString("setSearch('')", $pages);
        $this->assertStringContainsString('setPage(1)', $pages);
        $this->assertStringContainsString('setSiteOperationsFilters(siteOperationsDefaultFilters())', $pages);
        $this->assertStringContainsString('filterSiteOperationsRows(collection.rows, search, siteOperationsFilters)', $pages);
        $this->assertStringContainsString('rows={visibleRows}', $pages);
        $this->assertStringContainsString('resetToken={siteOperationsExportResetToken}', $pages);
        $this->assertStringContainsString('setError(null)', $export);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_authoritative_read_is_tenant_scoped_permission_guarded_and_foreign_tenant_fails_closed(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha-reset/route-api/site-operations', 'GET'),
        );
        $this->assertSame(RouteApiAdapterController::class.'@siteOperations', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-reset', ['tenant.view', 'execution.view']);
        $alphaSite = $this->site($alpha, 'Alpha Reset Site');
        $this->operation($alpha, $alphaSite, 'alpha.reset.visible', 'succeeded');

        $this->actingAs($reader)
            ->getJson('/tenants/alpha-reset/route-api/site-operations')
            ->assertOk()
            ->assertJsonPath('data.0.operation', 'alpha.reset.visible');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-reset', ['tenant.view']);
        $this->actingAs($limited)
            ->getJson('/tenants/limited-reset/route-api/site-operations')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreign = $this->membership($foreignOwner, 'foreign-reset', ['tenant.view', 'execution.view']);
        $foreignSite = $this->site($foreign, 'Foreign Reset Site');
        $this->operation($foreign, $foreignSite, 'foreign.reset.hidden', 'failed');

        $this->actingAs($reader)
            ->getJson('/tenants/foreign-reset/route-api/site-operations')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-reset-role-'.$user->id]);

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
            'details' => ['source' => 'reset-filter-test'],
            'affected_records' => 1,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
