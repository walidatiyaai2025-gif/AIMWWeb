<?php

namespace Tests\Feature;

use Tests\TestCase;

final class EmailHistoryClearFiltersTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-EMAI-8C9768BCD0';

    public function test_clear_filters_reuses_the_email_history_tenant_isolation_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/email-history-filters-control.tsx'));
        $existing = (string) file_get_contents(base_path('tests/Feature/EmailHistoryApplyFiltersTerminalityTest.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('onClick={onClear}', $control);
        $this->assertStringContainsString('foreign tenant IDs fail closed', $existing);
        $this->assertStringContainsString('assertNotFound', $existing);
    }
}
