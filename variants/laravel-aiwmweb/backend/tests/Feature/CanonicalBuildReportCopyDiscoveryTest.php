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

        $target = 'AIMW-SEO-C48570747C';
        $matches = [];

        $walk = function (mixed $value) use (&$walk, &$matches, $target): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['operation_id'] ?? $value['operationId'] ?? $value['id'] ?? null) === $target) {
                $matches[] = $value;
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        $walk($payload);

        throw new RuntimeException(json_encode([
            'target' => $target,
            'matches' => $matches,
            'totals' => $payload['totals'] ?? null,
            'visible_controls' => $payload['visible_controls'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
