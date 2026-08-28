<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlatformPendingContractDiscoveryTest extends TestCase
{
    public function test_emit_the_exact_canonical_platform_row_for_this_wave(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($path);

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $row = collect($payload['operations'] ?? [])->firstWhere('operation_id', 'AIMW-PLAT-18A8EE0324');

        $this->assertIsArray($row, 'Canonical operation AIMW-PLAT-18A8EE0324 was not found.');

        $this->fail('PCC_DISCOVERY_ROW='.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
