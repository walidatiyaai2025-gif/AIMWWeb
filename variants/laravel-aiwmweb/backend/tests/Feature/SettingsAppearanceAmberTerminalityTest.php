<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SettingsAppearanceAmberTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-CF4BE4EC3B';

    public function test_set_amber_is_bound_to_the_guarded_settings_runtime_without_server_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-appearance-light-control.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-source-handler="SetAmber"', $control);
        $this->assertStringContainsString("const THEME_STORAGE_KEY = 'aiwm-color-theme';", $control);
        $this->assertStringContainsString("document.documentElement.dataset.theme = 'amber'", $control);
        $this->assertStringContainsString("window.localStorage.setItem(THEME_STORAGE_KEY, 'amber')", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringNotContainsString('apiRequest', $control);

        $this->assertStringContainsString("r('settings', '/settings'", $core);
        $this->assertStringContainsString("permission: 'tenant.view', kind: 'settings'", $core);
        $this->assertStringContainsString("if (route.key === 'settings')", $app);
        $this->assertStringContainsString('<SettingsAppearanceLightControl context={context} />', $app);
    }
}
