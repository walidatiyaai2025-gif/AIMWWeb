<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteDiagnosticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteConnectionCopyDiagnosticsTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-BILL-D4E1DA62D9';

    public function test_source_and_laravel_frontend_bind_exact_copy_diagnostics_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}/connection"', $source);
        $this->assertStringContainsString('@page "/sites/{Id:guid}/operations"', $source);
        $this->assertStringContainsString('@onclick="CopyDiagnosticsAsync"', $source);
        $this->assertStringContainsString('AI WordPress Manager - Site Diagnostics', $source);
        $this->assertStringContainsString('navigator.clipboard.writeText', $source);
        $this->assertStringContainsString('_history.Take(20)', $source);

        $frontend = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('formatSiteDiagnosticsReport', $frontend);
        $this->assertStringContainsString('navigator.clipboard', $frontend);
        $this->assertStringContainsString('history.slice(0, 20)', $frontend);
        $this->assertStringNotContainsString('provider_secret', $frontend);
        $this->assertStringNotContainsString('application_password', $frontend);

        $connection = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@status', ltrim($connection->getActionName(), '\\'));
        $this->assertContains('auth', $connection->gatherMiddleware());
        $this->assertContains('tenant.context', $connection->gatherMiddleware());

        $operations = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/operations', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@operations', ltrim($operations->getActionName(), '\\'));
        $this->assertContains('auth', $operations->gatherMiddleware());
        $this->assertContains('tenant.context', $operations->gatherMiddleware());
    }
}
