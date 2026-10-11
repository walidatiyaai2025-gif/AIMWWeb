<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteDiagnosticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteConnectionRetestTerminalityTest extends TestCase
{
    public function test_retest_contract_is_bound_to_the_real_tenant_scoped_recheck_route(): void
    {
        $this->assertSame('AIMW-BILL-E747D0E843', SiteDiagnosticsController::RETEST_OPERATION_ID);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection/recheck', 'POST'));
        $this->assertSame(SiteDiagnosticsController::class.'@recheck', ltrim($route->getActionName(), '\\'));
        $this->assertSame(SiteDiagnosticsController::RETEST_OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $frontend = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $this->assertStringContainsString(SiteDiagnosticsController::RETEST_OPERATION_ID, $frontend);
        $this->assertStringContainsString('/connection/recheck', $frontend);
        $this->assertStringContainsString('connector.manage', $frontend);
    }
}
