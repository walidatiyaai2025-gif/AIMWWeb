<?php

namespace Tests\Feature;

use App\Http\Controllers\AiProviderSettingsReadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SettingsAiProvidersLinkVisibleTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-AI-8205320842';

    public function test_exact_pending_operation_is_bound_to_the_production_settings_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $frontend = file_get_contents(resource_path('js/settings-ai-providers-link-control.tsx'));

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/settings', $operation['route_screen']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("context.permissions.includes('settings.manage')", $frontend);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/settings/ai-providers')", $frontend);
    }

    public function test_destination_is_the_existing_guarded_tenant_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-providers', 'GET'));

        $this->assertSame(AiProviderSettingsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('tenant.settings.ai-providers', $route->getName());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }
}
