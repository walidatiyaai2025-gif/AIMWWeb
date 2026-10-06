<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminBillingSupportSettingsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-7DACB1EFDF';

    public function test_canonical_operation_and_workspace_route_are_platform_admin_tenant_scoped(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('/settings -> /settings', $operation['visible_control']);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/billing-support', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.admin-billing-support', $route->getName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
        $this->assertContains('platform.admin', $middleware);
    }

    public function test_foreign_tenant_and_non_platform_admin_access_fail_closed(): void
    {
        $alphaAdmin = User::factory()->create(['platform_admin' => true]);
        $this->membership($alphaAdmin, 'alpha');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $this->membership($betaAdmin, 'beta');

        $this->actingAs($betaAdmin)
            ->get('/tenants/alpha/admin/billing-support')
            ->assertNotFound();

        $alphaMember = User::factory()->create(['platform_admin' => false]);
        $this->membership($alphaMember, 'alpha');

        $this->actingAs($alphaMember)
            ->get('/tenants/alpha/admin/billing-support')
            ->assertForbidden();

        $this->actingAs($alphaAdmin)
            ->get('/tenants/alpha/admin/billing-support')
            ->assertOk();
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
