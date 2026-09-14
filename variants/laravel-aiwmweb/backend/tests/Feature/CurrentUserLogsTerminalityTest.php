<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentUserLogsTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-IDEN-CD4ADA5087';

    private const EVIDENCE_PATH = 'variants/laravel-aiwmweb/docs/closure-evidence/current-user-logs-terminality.json';

    public function test_current_user_logs_operation_is_generator_terminal_on_final_evidence_source(): void
    {
        $reconciliation = $this->jsonDocument('../docs/operation-parity-reconciliation.json');
        $manifest = $this->jsonDocument('../docs/operation-parity-evidence-sources.json');
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

        $focusedEvidenceSourceSha = $manifest['focused_closure_evidence_source_sha'] ?? null;
        $this->assertIsString($focusedEvidenceSourceSha);
        $this->assertNotSame('', $focusedEvidenceSourceSha);
        $this->assertSame($focusedEvidenceSourceSha, $operation['reconciliation']['source_sha'] ?? null, 'Reconciliation must be materialized from the manifest current focused evidence source.');

        $focusedTerminals = $reconciliation['validation']['focused_closure_contract_terminals'] ?? [];
        $this->assertContains(self::OPERATION_ID, $focusedTerminals);
        $this->assertTrue($reconciliation['validation']['passed'] ?? false);
    }

    public function test_manifest_and_closure_evidence_are_not_stale_after_generator_materialization(): void
    {
        $manifest = $this->jsonDocument('../docs/operation-parity-evidence-sources.json');
        $evidence = $this->jsonDocument('../docs/closure-evidence/current-user-logs-terminality.json');

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertTrue($evidence['terminality']['generator_backed_reconciliation_passed'] ?? false);
        $this->assertTrue($evidence['terminality']['final_exact_head_ci_required'] ?? false);

        $manifestEvidencePath = null;
        foreach ($manifest['countable_sources'] ?? [] as $source) {
            $operationEvidence = $source['operation_evidence'][self::OPERATION_ID] ?? null;
            if (is_array($operationEvidence)) {
                $manifestEvidencePath = $operationEvidence['evidence_path'] ?? null;
                break;
            }
        }

        $this->assertSame(self::EVIDENCE_PATH, $manifestEvidencePath, 'Manifest must retain this operation closure evidence after later generator materializations.');
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
