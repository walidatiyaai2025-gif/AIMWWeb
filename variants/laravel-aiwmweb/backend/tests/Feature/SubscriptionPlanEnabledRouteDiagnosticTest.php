<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionPlanEnabledController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SubscriptionPlanEnabledRouteDiagnosticTest extends TestCase
{
    private function route()
    {
        return Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans/7/enabled', 'PATCH'));
    }

    public function test_action_is_invokable_notation(): void
    {
        $this->assertSame(SubscriptionPlanEnabledController::class.'@__invoke', $this->route()->getActionName());
    }

    public function test_action_is_class_only_notation(): void
    {
        $this->assertSame(SubscriptionPlanEnabledController::class, $this->route()->getActionName());
    }

    public function test_route_has_web_middleware(): void
    {
        $this->assertContains('web', $this->route()->gatherMiddleware());
    }

    public function test_route_has_auth_middleware(): void
    {
        $this->assertContains('auth', $this->route()->gatherMiddleware());
    }

    public function test_route_has_tenant_context_middleware(): void
    {
        $this->assertContains('tenant.context', $this->route()->gatherMiddleware());
    }

    public function test_view_has_operation_marker(): void
    {
        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));
        $this->assertStringContainsString('AIMW-BILL-812D1C53B6', $view);
    }

    public function test_view_has_csrf_marker(): void
    {
        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));
        $this->assertStringContainsString('@csrf', $view);
    }

    public function test_view_has_expected_enabled_marker(): void
    {
        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));
        $this->assertStringContainsString('expected_enabled', $view);
    }
}
