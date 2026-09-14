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

    public function test_runtime_binds_the_exact_close_marker_only_to_the_real_close_button(): void
    {
        $control = (string) file_get_contents(resource_path('js/quick-actions-toggle-control.tsx'));

        $this->assertStringContainsString(
            "export const QUICK_ACTIONS_CLOSE_OPERATION = '".self::OPERATION_ID."';",
            $control,
        );
        $marker = 'data-canonical-operation={QUICK_ACTIONS_CLOSE_OPERATION}';
        $markerPosition = strpos($control, $marker);
        $this->assertNotFalse($markerPosition);

        $beforeMarker = substr($control, 0, $markerPosition);
        $buttonStart = strrpos($beforeMarker, '<button');
        $buttonEnd = strpos($control, '</button>', $markerPosition);
        $this->assertNotFalse($buttonStart);
        $this->assertNotFalse($buttonEnd);

        $closeButton = substr($control, $buttonStart, $buttonEnd + strlen('</button>') - $buttonStart);
        $this->assertStringContainsString($marker, $closeButton);
        $this->assertStringContainsString(
            "aria-label={locale === 'ar' ? 'إغلاق الإجراءات السريعة' : 'Close quick actions'}",
            $closeButton,
        );
        $this->assertStringContainsString('onClick={close}', $closeButton);
        $this->assertStringNotContainsString('QUICK_ACTIONS_TOGGLE_OPERATION', $closeButton);
        $this->assertStringContainsString('triggerRef.current?.focus()', $control);
    }
}
