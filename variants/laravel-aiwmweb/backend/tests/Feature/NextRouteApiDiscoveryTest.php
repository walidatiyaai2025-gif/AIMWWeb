<?php

namespace Tests\Feature;

use Tests\TestCase;

class NextRouteApiDiscoveryTest extends TestCase
{
    public function test_emit_exact_about_build_canonical_row(): void
    {
        $reconciliation = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $row = collect($reconciliation['operations'])
            ->firstWhere('operation_id', 'AIMW-CONT-81B4B20D2D');

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state'] ?? null);
        $this->assertSame('route', $row['kind'] ?? null);
        $this->fail('CANONICAL_ABOUT_BUILD_ROW='.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
