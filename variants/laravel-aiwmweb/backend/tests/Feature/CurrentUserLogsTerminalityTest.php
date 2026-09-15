<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentUserLogsTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-IDEN-CD4ADA5087';

    private const EVIDENCE_SOURCE_SHA = '8d9447700793f408d16aadd2d9c0df1657908c4c';

    public function test_current_user_logs_operation_is_generator_terminal_on_final_evidence_source(): void
    {
        $reconciliation = $this->jsonDocument('../docs/operation-parity-reconciliation.json');

        $this->assertSame(931, $reconciliation['totals']['total'] ?? null);
        $this->assertSame(569, $reconciliation['totals']['terminal'] ?? null);
        $this->assertSame(362, $reconciliation['totals']['pending'] ?? null);
        $this->assertSame(0, $reconciliation['totals']['blocked'] ?? null);

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
        $this->assertSame(self::EVIDENCE_SOURCE_SHA, $operation['reconciliation']['source_sha'] ?? null);

        $focusedTerminals = $reconciliation['validation']['focused_closure_contract_terminals'] ?? [];
        $this->assertContains(self::OPERATION_ID, $focusedTerminals);
        $this->assertTrue($reconciliation['validation']['passed'] ?? false);
    }

    public function test_manifest_and_closure_evidence_are_not_stale_after_generator_materialization(): void
    {
        $manifest = $this->jsonDocument('../docs/operation-parity-evidence-sources.json');
        $evidence = $this->jsonDocument('../docs/closure-evidence/current-user-logs-terminality.json');

        $this->assertSame(self::EVIDENCE_SOURCE_SHA, $manifest['focused_closure_evidence_source_sha'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertTrue($evidence['terminality']['generator_backed_reconciliation_passed'] ?? false);
        $this->assertTrue($evidence['terminality']['final_exact_head_ci_required'] ?? false);
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
