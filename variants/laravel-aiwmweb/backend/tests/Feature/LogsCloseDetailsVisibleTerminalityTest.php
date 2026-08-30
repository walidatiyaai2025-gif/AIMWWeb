<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LogsCloseDetailsVisibleTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-AI-024BB0971B';

    public function test_exact_canonical_operation_is_the_pending_logs_close_details_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/logs | /module/logs', $operation['route_screen']);
        $this->assertSame('CloseDetails [CloseDetails]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);

        $source = file_get_contents(base_path('../../src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor'));
        $frontend = file_get_contents(resource_path('js/logs-close-details-control.tsx'));
        $this->assertStringContainsString('private void CloseDetails() => _selectedLine = null;', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('onClick={() => setSelected(null)}', $frontend);
    }

    public function test_close_control_reuses_the_existing_authenticated_tenant_logs_read_authority(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/logs', 'GET'));

        $this->assertSame(AdminOperationsController::class.'@logs', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());

        $frontend = file_get_contents(resource_path('js/logs-close-details-control.tsx'));
        $this->assertStringContainsString("context.permissions.includes('operations.manage')", $frontend);
        $this->assertStringContainsString("context.permissions.includes('diagnostics.view')", $frontend);
        $this->assertStringContainsString('`/tenants/${context.tenant.slug}/admin/logs`', $frontend);
        $this->assertStringNotContainsString("method: 'POST'", $frontend);
        $this->assertStringNotContainsString("method: 'PUT'", $frontend);
        $this->assertStringNotContainsString("method: 'PATCH'", $frontend);
        $this->assertStringNotContainsString("method: 'DELETE'", $frontend);
    }
}
