<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApprovalExecutionLinkTerminalityTest extends TestCase
{
    public function test_canonical_operation_is_the_approval_execution_center_visible_control(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($payload['operations'])->firstWhere('operation_id', 'AIMW-APPR-B360D1C8BA');

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('approvals', $operation['domain']);
        $this->assertSame('/approvals', $operation['route_screen']);
        $this->assertSame('/module/execution -> /module/execution', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_target_is_the_existing_guarded_canonical_execution_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/execution', 'GET'));

        $this->assertSame('canonical.workspace.execution', $route->getName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    public function test_approval_route_renders_the_tenant_safe_execution_link_contract(): void
    {
        $appSource = file_get_contents(resource_path('js/app.tsx'));
        $helperSource = file_get_contents(resource_path('js/approvalQueue.ts'));

        $this->assertStringContainsString("route.key === 'approvals'", $appSource);
        $this->assertStringContainsString('approvalExecutionCenterHref(context)', $appSource);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/execution')", $helperSource);
        $this->assertStringNotContainsString("return '/module/execution'", $helperSource);
    }
}
