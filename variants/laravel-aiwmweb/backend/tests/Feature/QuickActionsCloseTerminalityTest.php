<?php

namespace Tests\Feature;

use Tests\TestCase;

class QuickActionsCloseTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-17BC7DA9E5';

    public function test_exact_canonical_operation_is_the_pending_quick_actions_close_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:QuickActions', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? [Close]', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Layout/QuickActions.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('none', $operation['external_dependency']);
    }

    public function test_runtime_publishes_the_exact_close_contract_without_mutation_or_route_synthesis(): void
    {
        $control = (string) file_get_contents(resource_path('js/quick-actions-toggle-control.tsx'));

        $this->assertStringContainsString(
            "export const QUICK_ACTIONS_CLOSE_OPERATION = '".self::OPERATION_ID."';",
            $control,
        );
        $this->assertStringContainsString(
            'data-canonical-operation={QUICK_ACTIONS_CLOSE_OPERATION}',
            $control,
        );
        $this->assertStringContainsString(
            "aria-label={locale === 'ar' ? 'إغلاق الإجراءات السريعة' : 'Close quick actions'}",
            $control,
        );
        $this->assertStringContainsString('onClick={close}', $control);
        $this->assertStringContainsString('setOpen(false)', $control);
        $this->assertStringContainsString('triggerRef.current?.focus()', $control);
        $this->assertSame(1, substr_count($control, self::OPERATION_ID));
    }
}
