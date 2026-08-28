<?php

namespace Tests\Feature;

use Tests\TestCase;

class OneCanonicalOperationDiscoveryTest extends TestCase
{
    public function test_emit_exact_pending_about_build_visible_controls(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $ids = ['AIMW-CONT-EBD53650BC', 'AIMW-SYNC-68B372C9FE'];
        $rows = collect($payload['operations'])
            ->filter(static fn (array $row): bool => in_array($row['operation_id'] ?? null, $ids, true))
            ->values()
            ->all();

        $this->fail('ABOUT_BUILD_VISIBLE_CONTROL_ROWS='.json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
