<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentPlannerAiCenterLinkTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-BILL-CAFE798AA3';

    public function test_source_and_laravel_control_bind_exact_ai_center_navigation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor'));
        $control = (string) file_get_contents(resource_path('js/content-planner-ai-center-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString('<a class="btn" href="/ai-center">✦ AI Center</a>', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/ai-center:/ai-center',
            $control,
        );
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/ai-center')", $control);
        $this->assertStringContainsString("route.key === 'content-planner'", $app);
        $this->assertStringContainsString('ContentPlannerAiCenterLinkControl context={context}', $app);
        $this->assertStringContainsString("r('ai-center', '/ai-center'", $core);
        $this->assertStringContainsString("permission: 'ai.use'", $core);
    }

    public function test_direct_tenant_ai_center_shell_is_authenticated_and_tenant_scoped(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/ai-center', 'GET'));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    public function test_control_contains_no_mutation_or_unqualified_destination(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-planner-ai-center-link-control.tsx'));

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('method:', $control);
        $this->assertStringNotContainsString('to="/ai-center"', $control);
        $this->assertStringContainsString("hasPermission(context, plannerRoute.permission)", $control);
        $this->assertStringContainsString("hasPermission(context, aiCenterRoute.permission)", $control);
    }
}
