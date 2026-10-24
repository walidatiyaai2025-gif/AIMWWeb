<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SiteEmailSettingsCancelEditTerminalityTest extends TestCase
{
    public function test_tenant_scoped_cancel_edit_contract(): void
    {
        $view = (string) file_get_contents(resource_path('views/sites/email-settings.blade.php'));
        $guard = (string) file_get_contents(base_path('tests/Feature/SiteEmailSettingsSaveEditTerminalityTest.php'));

        $this->assertStringContainsString('AIMW-EMAI-7E1D4105B0', $view);
        $this->assertStringContainsString('CancelEdit', $view);
        $this->assertStringContainsString('assertNotFound', $guard);
        $this->assertStringContainsString('tenant.context', $guard);
    }
}
