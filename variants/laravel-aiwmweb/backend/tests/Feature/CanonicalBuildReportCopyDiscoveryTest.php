<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

final class CanonicalBuildReportCopyDiscoveryTest extends TestCase
{
    public function test_dump_target_row_and_global_counts(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $target = 'AIMW-SYNC-68B372C9FE';
        $matches = [];
        $statusCounts = [];

        $walk = function (mixed $value) use (&$walk, &$matches, &$statusCounts, $target): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['operation_id'] ?? $value['operationId'] ?? $value['id'] ?? null) === $target) {
                $matches[] = $value;
            }

            $status = $value['status'] ?? $value['state'] ?? null;
            if (is_string($status)) {
                $normalized = strtoupper($status);
                if (in_array($normalized, ['PENDING', 'TERMINAL', 'TERMINALIZED', 'CLOSED', 'COMPLETE', 'COMPLETED'], true)) {
                    $statusCounts[$normalized] = ($statusCounts[$normalized] ?? 0) + 1;
                }
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        $walk($payload);

        throw new RuntimeException(json_encode([
            'target' => $target,
            'matches' => $matches,
            'status_counts' => $statusCounts,
            'top_level_keys' => array_keys($payload),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
