<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ApplicationUsersRequestResetPasswordTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-SYNC-9ABD7DEDBB';

    public function test_exact_canonical_row_is_the_non_mutating_reset_review_request(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/admin/application-users', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? [RequestResetPasswordAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_review_gate_is_preserved_before_confirm_reset_mutation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/application-user-password-reset-control.tsx'),
        );

        $this->assertStringContainsString('OnClick="RequestResetPasswordAsync"', $source);
        $this->assertStringContainsString('private Task RequestResetPasswordAsync(MouseEventArgs _)', $source);
        $this->assertStringContainsString('if (!ValidateReset()) { _isError = true; return Task.CompletedTask; }', $source);
        $this->assertStringContainsString('_resetConfirmationOpen = true;', $source);
        $this->assertStringContainsString('OnConfirm="ConfirmResetPasswordAsync"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID', $control);
        $this->assertStringContainsString('data-canonical-operation={APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID}', $control);
        $this->assertStringContainsString('setReviewRequested(true);', $control);
        $this->assertStringContainsString('if (password !== passwordConfirmation)', $control);
        $this->assertStringContainsString('const requestResetReview = () => {', $control);
        $this->assertStringContainsString('type="button"', $control);
        $this->assertStringContainsString('onClick={requestResetReview}', $control);
        $this->assertStringContainsString('if (! reviewRequested || resetMutation.isPending) return;', $control);
        $this->assertStringContainsString('resetMutation.mutate();', $control);
    }

    public function test_request_step_does_not_duplicate_the_existing_password_reset_operation(): void
    {
        $control = (string) file_get_contents(
            resource_path('js/application-user-password-reset-control.tsx'),
        );

        $this->assertStringContainsString(
            "APPLICATION_USER_PASSWORD_RESET_OPERATION_ID = 'AIMW-BILL-3BE40F2E00'",
            $control,
        );
        $this->assertStringContainsString(
            "APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID = 'AIMW-SYNC-9ABD7DEDBB'",
            $control,
        );
        $this->assertStringContainsString(
            'data-canonical-operation={APPLICATION_USER_PASSWORD_RESET_OPERATION_ID}',
            $control,
        );
        $this->assertSame(1, substr_count($control, 'data-canonical-operation={APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID}'));
    }

    public function test_terminal_evidence_is_operation_scoped_and_read_only(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/application-users-request-reset-password-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id']);
        $this->assertSame('ADAPTED', $evidence['terminal_state']);
        $this->assertFalse($evidence['mutation']);
        $this->assertSame('AIMW-BILL-3BE40F2E00', $evidence['separate_confirm_mutation_operation']);
        $this->assertTrue($evidence['tenant_owned']);
    }
}
