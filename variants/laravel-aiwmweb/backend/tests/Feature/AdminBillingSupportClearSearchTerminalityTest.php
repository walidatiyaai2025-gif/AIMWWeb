<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBillingSupportController;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminBillingSupportClearSearchTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-B4F030B126';

    public function test_exact_canonical_operation_is_the_pending_billing_support_clear_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('ClearSearchAsync [ClearSearchAsync]', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_workspace_and_authoritative_reread_keep_tenant_and_platform_admin_boundaries(): void
    {
        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/billing-support', 'GET'));
        $workspaceMiddleware = $workspace->gatherMiddleware();

        $this->assertSame('canonical.workspace.admin-billing-support', $workspace->getName());
        $this->assertContains('auth', $workspaceMiddleware);
        $this->assertContains('tenant.context', $workspaceMiddleware);
        $this->assertContains('platform.admin', $workspaceMiddleware);

        $api = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions?page=1', 'GET'));
        $apiMiddleware = $api->gatherMiddleware();

        $this->assertSame(AdminBillingSupportController::class.'@index', ltrim($api->getActionName(), '\\'));
        $this->assertSame('canonical.api.billing-support.index', $api->getName());
        $this->assertContains('auth', $apiMiddleware);
        $this->assertContains('tenant.context', $apiMiddleware);
        $this->assertContains('platform.admin', $apiMiddleware);
    }

    public function test_foreign_tenant_and_non_platform_admin_rereads_fail_closed(): void
    {
        $alphaAdmin = User::factory()->create(['platform_admin' => true]);
        $this->membership($alphaAdmin, 'alpha');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $this->membership($betaAdmin, 'beta');

        $this->actingAs($betaAdmin)
            ->get('/api/tenants/alpha/billing/admin/subscriptions?page=1')
            ->assertNotFound();

        $alphaMember = User::factory()->create(['platform_admin' => false]);
        $this->membership($alphaMember, 'alpha');

        $this->actingAs($alphaMember)
            ->get('/api/tenants/alpha/billing/admin/subscriptions?page=1')
            ->assertForbidden();

        $this->actingAs($alphaAdmin)
            ->get('/api/tenants/alpha/billing/admin/subscriptions?page=1')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_source_and_laravel_control_preserve_clear_then_reread_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/AdminBillingSupport.razor'));
        $control = (string) file_get_contents(resource_path('js/admin-billing-support-clear-search-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('private async Task ClearSearchAsync()', $source);
        $this->assertStringContainsString('_query = string.Empty;', $source);
        $this->assertStringContainsString('_detail = null;', $source);
        $this->assertStringContainsString('_message = string.Empty;', $source);
        $this->assertStringContainsString('await SearchAsync();', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('clearAdminBillingSupportSearch', $pages);
        $this->assertStringContainsString("setSearchInput('');", $pages);
        $this->assertStringContainsString("setSearch('');", $pages);
        $this->assertStringContainsString('setPage(1);', $pages);
        $this->assertStringContainsString('setDialog(null);', $pages);
        $this->assertStringContainsString('queryClient.fetchQuery', $pages);
        $this->assertStringContainsString("route.key === 'admin-billing-support'", $pages);
    }

    private function membership(User $user, string $slug): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        app(TenantContext::class)->activate($tenant);
        TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $tenant;
    }
}
