<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteReliabilityApplyFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-40CD7F080F';

    public function test_source_apply_filters_semantics_are_bound_to_the_real_laravel_control_and_backend(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/site-reliability-filters-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/RouteApiAdapterController.php'));

        $this->assertStringContainsString('@bind="_periodDays"', $source);
        $this->assertStringContainsString('@bind="_minimumOperations"', $source);
        $this->assertStringContainsString('@onclick="ApplyFilters"', $source);
        $this->assertStringContainsString('private void ApplyFilters() => BuildRows();', $source);
        $this->assertStringContainsString('_periodDays = 30;', $source);
        $this->assertStringContainsString('_minimumOperations = 1;', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('SiteReliabilityFiltersControl', $pages);
        $this->assertStringContainsString('period_days: siteReliabilityFilters.periodDays', $pages);
        $this->assertStringContainsString('minimum_operations: siteReliabilityFilters.minimumOperations', $pages);
        $this->assertStringContainsString("'period_days' => ['nullable', 'integer', 'in:7,30,90']", $controller);
        $this->assertStringContainsString("'minimum_operations' => ['nullable', 'integer', 'in:1,3,5,10']", $controller);
        $this->assertStringContainsString('now()->subDays($periodDays)', $controller);
        $this->assertStringContainsString("\$row['total_operations'] >= \$minimumOperations", $controller);
    }

    public function test_period_and_minimum_filters_recompute_authoritative_retained_history_without_mutation(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-reliability-filters', ['tenant.view', 'sites.view']);
        $primary = $this->site($alpha, 'Alpha Filter Primary');
        $secondary = $this->site($alpha, 'Alpha Filter Secondary');

        $this->operation($alpha, $primary, 'connection.test', 'succeeded', now()->subDays(2));
        $this->operation($alpha, $primary, 'sync.content', 'failed', now()->subDays(4));
        $this->operation($alpha, $primary, 'synchronization.scan', 'succeeded', now()->subDays(20));
        $this->operation($alpha, $primary, 'connection.test', 'succeeded', now()->subDays(60));
        $this->operation($alpha, $primary, 'billing.refresh', 'succeeded', now()->subDay());
        $this->operation($alpha, $secondary, 'sync.content', 'succeeded', now()->subDays(3));

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-reliability-filters', ['tenant.view', 'sites.view']);
        $foreignSite = $this->site($beta, 'Beta Hidden Filter Site');
        $this->operation($beta, $foreignSite, 'sync.content', 'failed', now()->subDay());

        $before = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $this->actingAs($reader)
            ->getJson('/tenants/alpha-reliability-filters/route-api/site-reliability?period_days=7&minimum_operations=3')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.period_days', 7)
            ->assertJsonPath('meta.minimum_operations', 3);

        $this->actingAs($reader)
            ->getJson('/tenants/alpha-reliability-filters/route-api/site-reliability?period_days=30&minimum_operations=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_name', 'Alpha Filter Primary')
            ->assertJsonPath('data.0.total_operations', 3)
            ->assertJsonPath('data.0.successful', 2)
            ->assertJsonPath('data.0.failed', 1)
            ->assertJsonPath('meta.period_days', 30)
            ->assertJsonPath('meta.minimum_operations', 3)
            ->assertJsonMissing(['site_name' => 'Beta Hidden Filter Site']);

        $this->actingAs($reader)
            ->getJson('/tenants/alpha-reliability-filters/route-api/site-reliability?period_days=90&minimum_operations=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['site_name' => 'Alpha Filter Primary', 'total_operations' => 4])
            ->assertJsonFragment(['site_name' => 'Alpha Filter Secondary', 'total_operations' => 1])
            ->assertJsonMissing(['site_name' => 'Beta Hidden Filter Site']);

        $this->assertSame($before, SiteOperationHistory::query()->withoutGlobalScopes()->count());
    }

    public function test_forged_filter_values_permission_and_foreign_tenant_requests_fail_closed(): void
    {
        $reader = User::factory()->create();
        $this->membership($reader, 'alpha-filter-guard', ['tenant.view', 'sites.view']);

        $this->actingAs($reader)
            ->getJson('/tenants/alpha-filter-guard/route-api/site-reliability?period_days=14&minimum_operations=2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_days', 'minimum_operations']);

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-filter-guard', ['tenant.view']);
        $this->actingAs($limited)
            ->getJson('/tenants/limited-filter-guard/route-api/site-reliability?period_days=30&minimum_operations=1')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $this->membership($foreignOwner, 'beta-filter-guard', ['tenant.view', 'sites.view']);
        $this->actingAs($reader)
            ->getJson('/tenants/beta-filter-guard/route-api/site-reliability?period_days=30&minimum_operations=1')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => Str::headline($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-filter-role-'.$user->id]);

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

    private function operation(Tenant $tenant, Site $site, string $operation, string $status, $startedAt): SiteOperationHistory
    {
        app(TenantContext::class)->activate($tenant);
        $row = SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'correlation_id' => (string) Str::uuid(),
            'operation' => $operation,
            'status' => $status,
            'message' => $status === 'succeeded' ? 'Completed' : 'Failed',
            'details' => ['source' => 'reliability-filter-test'],
            'affected_records' => 1,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addSecond(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
