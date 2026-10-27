<?php

namespace Tests\Feature;

use Tests\TestCase;

final class EmailHistoryClearFiltersTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-EMAI-8C9768BCD0';

    public function test_clear_filters_adapts_to_the_existing_tenant_safe_grid_clear_contract(): void
    {
        $emailControl = (string) file_get_contents(resource_path('js/email-history-filters-control.tsx'));
        $sharedControl = (string) file_get_contents(resource_path('js/app-data-grid-clear-filters-control.tsx'));
        $workspace = (string) file_get_contents(resource_path('js/pages.tsx'));
        $existing = (string) file_get_contents(base_path('tests/Feature/EmailHistoryApplyFiltersTerminalityTest.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $emailControl);
        $this->assertStringContainsString('data-canonical-operation-alias={canonicalOperationAlias}', $sharedControl);
        $this->assertStringContainsString('canonicalOperationAlias={EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID}', $workspace);
        $this->assertStringContainsString('foreign tenant IDs fail closed', $existing);
        $this->assertStringContainsString('assertNotFound', $existing);
    }
}
