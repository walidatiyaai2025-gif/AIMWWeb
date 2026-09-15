<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentUserLogsTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-IDEN-CD4ADA5087';

    /**
     * Historical generator source recorded when AIMW-IDEN-CD4ADA5087 was
     * materialized. Later focused closures legitimately move the manifest's
     * global focused source pointer, but must never rewrite this operation's
     * reconciliation provenance.
     */
    private const EVIDENCE_SOURCE_SHA = 'c9eac52c1cb1bc212aa35776edae8e0e20a9f41f';

    public function test_current_user_logs_operation_remains_generator_terminal_after_later_closures(): void
    {
        $reconciliation = $this->jsonDocument('../docs/operation-parity-reconciliation.json');
        $evidence = $this->jsonDocument('../docs/closure-evidence/current-user-logs-terminality.json');

        $total = $reconciliation['totals']['total'] ?? null;
        $terminal = $reconciliation['totals']['terminal'] ?? null;
        $pending = $reconciliation['totals']['pending'] ?? null;
        $blocked = $reconciliation['totals']['blocked'] ?? null;

        $this->assertSame(931, $total);
        $this->assertIsInt($terminal);
        $this->assertIsInt($pending);
        $this->assertIsInt($blocked);
        $this->assertSame($total, $terminal + $pending + $blocked, 'Generated parity totals must remain internally consistent.');

        $minimumTerminal = $evidence['canonical_denominator_contribution']['expected_terminal_after_this_operation'] ?? null;
        $maximumPending = $evidence['canonical_denominator_contribution']['expected_pending_after_this_operation'] ?? null;
        $this->assertIsInt($minimumTerminal);
        $this->assertIsInt($maximumPending);
        $this->assertGreaterThanOrEqual($minimumTerminal, $terminal, 'Later closures must not regress terminal coverage below this operation baseline.');
        $this->assertLessThanOrEqual($maximumPending, $pending, 'Later closures must not regress pending coverage above this operation baseline.');

        $operation = null;
        foreach ($reconciliation['operations'] ?? [] as $candidate) {
            if (($candidate['operation_id'] ?? null) === self::OPERATION_ID) {
                $operation = $candidate;
                break;
            }
        }

        $this->assertIsArray($operation, 'Canonical operation is missing from generated reconciliation.');
        $this->assertSame('ADAPTED', $operation['migration_state'] ?? null);
        $this->assertSame('focused_closure_contract', $operation['reconciliation']['evidence_mode'] ?? null);
        $this->assertSame(
            self::EVIDENCE_SOURCE_SHA,
            $operation['reconciliation']['source_sha'] ?? null,
            'Historical reconciliation provenance must remain pinned to the evidence source that terminalized this operation.'
        );

        $focusedTerminals = $reconciliation['validation']['focused_closure_contract_terminals'] ?? [];
        $this->assertContains(self::OPERATION_ID, $focusedTerminals);
        $this->assertTrue($reconciliation['validation']['passed'] ?? false);
    }

    public function test_closure_evidence_preserves_historical_generator_contract_after_later_materializations(): void
    {
        $evidence = $this->jsonDocument('../docs/closure-evidence/current-user-logs-terminality.json');

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('PENDING', $evidence['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertTrue($evidence['terminality']['generator_backed_reconciliation_passed'] ?? false);
        $this->assertTrue($evidence['terminality']['final_exact_head_ci_required'] ?? false);

        $implementationSha = $evidence['implementation_sha'] ?? null;
        $testedSha = $evidence['implementation_tested_sha'] ?? null;
        $this->assertIsString($implementationSha);
        $this->assertNotSame('', $implementationSha);
        $this->assertSame($implementationSha, $testedSha, 'Closure evidence must identify the exact implementation revision that received focused validation.');

        $contribution = $evidence['canonical_denominator_contribution'] ?? [];
        $this->assertSame(931, $contribution['total_operations'] ?? null);
        $this->assertSame(567, $contribution['previous_terminal'] ?? null);
        $this->assertSame(568, $contribution['expected_terminal_after_this_operation'] ?? null);
        $this->assertSame(364, $contribution['previous_pending'] ?? null);
        $this->assertSame(363, $contribution['expected_pending_after_this_operation'] ?? null);
        $this->assertSame(0, $contribution['previous_blocked'] ?? null);
        $this->assertSame(1, $contribution['delta_terminal'] ?? null);
        $this->assertSame(-1, $contribution['delta_pending'] ?? null);
    }

    private function jsonDocument(string $relativePath): array
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
