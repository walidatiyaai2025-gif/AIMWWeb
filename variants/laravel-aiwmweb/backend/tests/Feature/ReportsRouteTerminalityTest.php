<?php

namespace Tests\Feature;

use App\Http\Controllers\ApprovalsReportExportController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportsRouteTerminalityTest extends TestCase
{
    private const ROUTES = [
        'AIMW-CONT-5D18F49928' => ['canonical.workspace.reports', 'tenants/{tenant}/module/reports', '/module/reports'],
        'AIMW-CONT-8140D785B5' => ['canonical.alias.reports', 'tenants/{tenant}/reports', '/reports'],
    ];

    public function test_exact_report_route_operations_are_bound_to_the_real_guarded_report_controller(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rows = collect($ledger['operations'])->keyBy('operation_id');

        foreach (self::ROUTES as $operationId => [$routeName, $expectedUri, $sourceRoute]) {
            $row = $rows->get($operationId);
            $this->assertNotNull($row, "Missing canonical Reports route row {$operationId}");
            $this->assertSame('route', $row['kind'], $operationId);
            $this->assertSame($sourceRoute, $row['route_screen'], $operationId);
            $this->assertFalse((bool) $row['mutation'], $operationId);

            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Missing Laravel Reports route {$routeName}");
            $this->assertSame($expectedUri, $route->uri(), $operationId);
            $this->assertStringContainsString(
                ApprovalsReportExportController::class,
                ltrim($route->getActionName(), '\\'),
                $operationId,
            );
            $this->assertContains('auth', $route->gatherMiddleware(), $operationId);
            $this->assertContains('tenant.context', $route->gatherMiddleware(), $operationId);
        }
    }

    public function test_report_route_controller_keeps_explicit_authorization_and_tenant_match_boundaries(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/ApprovalsReportExportController.php'));
        $existingAcceptance = (string) file_get_contents(base_path('tests/Feature/ApprovalsReportExportTerminalityTest.php'));

        $this->assertStringContainsString("authorize('reports.view')", $controller);
        $this->assertStringContainsString('assertTenant($tenant, $context)', $controller);
        $this->assertStringContainsString("assertUnauthorized()", $existingAcceptance);
        $this->assertStringContainsString("assertForbidden()", $existingAcceptance);
        $this->assertStringContainsString("assertNotFound()", $existingAcceptance);
    }
}
