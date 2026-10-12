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

final class SiteReliabilityReloadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-8F4FA80ED6';

    public function test_reload_async_reads_only_authoritative_tenant_reliability_history_without_mutation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-reliability-reload-control.ts'));

        $this->assertStringContainsString('private async Task ReloadAsync()', $source);
        $this->assertStringContainsString('History.GetAll', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('runAuthoritativeSiteReliabilityReload', $pages);
        $this->assertStringContainsString('SITE_RELIABILITY_RELOAD_OPERATION_ID', $pages);

        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-reliability', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Reliability Site');
        $this->operation($alpha, $alphaSite, 'connection.test', 'succeeded', now()->subMinutes(4));
        $this->operation($alpha, $alphaSite, 'sync.content', 'failed', now()->subMinutes(2));
        $this->operation($alpha, $alphaSite, 'billing.refresh', 'succeeded', now()->subMinute());

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-reliability', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Reliability Site');
        $this->operation($beta, $betaSite, 'connection.test', 'failed', now()->subMinute());

        $before = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($reader)
            ->getJson('/tenants/alpha-reliability/route-api/site-reliability')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_name', 'Alpha Reliability Site')
            ->assertJsonPath('data.0.total_operations', 2)
            ->assertJsonPath('data.0.successful', 1)
            ->assertJsonPath('data.0.failed', 1)
            ->assertJsonPath('data.0.success_rate', 50)
            ->assertJsonPath('data.0.retained_history_only', true)
            ->assertJsonPath('meta.period_days', 30)
            ->assertJsonPath('meta.retained_history_limit', 2000)
            ->assertJsonPath('meta.live_health_monitor', false);

        $response->assertJsonMissing(['site_name' => 'Beta Reliability Site']);
        $this->assertSame($before, SiteOperationHistory::query()->withoutGlobalScopes()->count());

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha-reliability/route-api/site-reliability', 'GET'));
        $this->assertSame(RouteApiAdapterController::class.'@siteReliability', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_reload_async_permission_and_foreign_tenant_requests_fail_closed(): void
    {
        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-reliability', ['tenant.view']);
        $gammaSite = $this->site($gamma, 'Gamma Reliability Site');
        $this->operation($gamma, $gammaSite, 'sync.content', 'succeeded', now()->subMinute());

        $this->actingAs($viewer)
            ->getJson('/tenants/gamma-reliability/route-api/site-reliability')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreign = $this->membership($foreignOwner, 'foreign-reliability', ['tenant.view', 'sites.view']);
        $foreignSite = $this->site($foreign, 'Foreign Reliability Site');
        $this->operation($foreign, $foreignSite, 'connection.test', 'succeeded', now()->subMinute());

        $this->actingAs($viewer)
            ->getJson('/tenants/foreign-reliability/route-api/site-reliability')
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

    private function operation(Tenant $tenant, Site $site, string $operation, string $status, $startedAt): SiteOperationHistory
    {
        app(TenantContext::class)->activate($tenant);
        $row = SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'correlation_id' => (string) Str::uuid(),
            'operation' => $operation,
            'status' => $status,
            'message' => $status === 'succeeded' ? 'Completed' : 'Failed',
            'details' => ['source' => 'reliability-authoritative-test'],
            'affected_records' => 1,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addSecond(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
