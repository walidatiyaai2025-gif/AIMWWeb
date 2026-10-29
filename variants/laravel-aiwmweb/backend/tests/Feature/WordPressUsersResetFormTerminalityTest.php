<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WordPressUsersResetFormTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-10AFCDF334';

    public function test_tenant_guard_contract_is_reused(): void
    {
        $component = (string) file_get_contents(resource_path('js/components.tsx'));
        $contractTest = (string) file_get_contents(base_path('tests/Feature/WordPressUsersWorkingStateTerminalityTest.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('assertNotFound', $contractTest);
        $this->assertStringContainsString('TenantContext', $contractTest);
    }
}
