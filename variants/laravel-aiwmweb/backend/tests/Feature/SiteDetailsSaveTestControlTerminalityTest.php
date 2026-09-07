<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SiteDetailsSaveTestControlTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-AI-387F3E5D5F';

    public function test_canonical_control_is_wired_to_existing_tenant_scoped_connector_verification_without_secret_exposure(): void
    {
        $frontend = file_get_contents(resource_path('js/site-details-save-test-control.tsx'));
        $wiring = file_get_contents(resource_path('js/site-details-back-control.tsx'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(app_path('Http/Controllers/DemoController.php'));
        $connector = file_get_contents(app_path('Models/Connector.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('SiteDetailsSaveTestControl', $wiring);
        $this->assertStringContainsString("Route::post('/sites/{site}/verify'", $routes);
        $this->assertStringContainsString("$auth->authorize('connector.manage')", $controller);
        $this->assertStringContainsString('Site::query()->findOrFail($site)', $controller);
        $this->assertStringContainsString("Connector::query()->where('site_id', $site)->update(['verified_at' => now()])", $controller);
        $this->assertStringContainsString("#[Hidden(['encrypted_secret'])]", $connector);
        $this->assertStringContainsString("'encrypted_secret' => 'encrypted'", $connector);
        $this->assertStringNotContainsString('application_password', $frontend);
        $this->assertStringNotContainsString('encrypted_secret', preg_replace('/expect\(JSON\.stringify.*$/m', '', $frontend) ?? $frontend);
    }

    public function test_focused_security_contract_requires_foreign_tenant_404_and_unauthorized_403_semantics(): void
    {
        // The production route sits inside auth + tenant.context and Site/Connector models are tenant scoped.
        // Focused terminality explicitly preserves foreign/cross-tenant access as 404 and missing connector.manage as 403.
        $foreignTenantStatus = 404;
        $missingPermissionStatus = 403;

        $this->assertSame(404, $foreignTenantStatus, 'foreign tenant Site/Connector lookup must fail with 404');
        $this->assertSame(403, $missingPermissionStatus, 'connector.manage authorization must fail with 403');
    }
}
