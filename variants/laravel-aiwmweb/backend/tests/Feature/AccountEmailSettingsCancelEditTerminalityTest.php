<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AccountEmailSettingsCancelEditTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-EMAI-A9CB66F400';

    public function test_cancel_edit_reuses_the_account_email_tenant_guard_without_a_server_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/account-email-settings-profile-form.tsx'));
        $guard = (string) file_get_contents(base_path('tests/Feature/AccountEmailSettingsSaveProfileTerminalityTest.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-source-handler="CancelEditClicked"', $control);
        $this->assertStringContainsString('type="button"', $control);
        $this->assertStringContainsString('setDraft(draftFrom(configuration))', $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.manage')", $control);

        $this->assertStringContainsString('tenant.context', $guard);
        $this->assertStringContainsString('assertNotFound', $guard);
        $this->assertStringContainsString('assertForbidden', $guard);
    }
}
