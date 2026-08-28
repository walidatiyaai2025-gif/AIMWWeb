<?php

namespace Tests\Feature;

use Tests\TestCase;

class NextRouteApiDiscoveryTest extends TestCase
{
    public function test_emit_unclaimed_pending_route_api_candidates(): void
    {
        $reconciliation = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $evidence = json_decode(
            file_get_contents(base_path('../docs/closure-evidence/route-api-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $claimed = array_column($evidence['operations'], 'operation_id');
        $reserved = [
            'AIMW-CONT-2F2E40D7F0',
            'AIMW-CONT-270F69CE9A',
            'AIMW-CONT-475267F150',
            'AIMW-PLAT-18A8EE0324',
            'AIMW-CONT-6B051BD8C0',
            'AIMW-CONT-9CF12067E6',
        ];

        $rows = collect($reconciliation['operations'])
            ->filter(fn (array $row): bool => ($row['status'] ?? null) === 'PENDING')
            ->filter(fn (array $row): bool => in_array($row['kind'] ?? null, ['route', 'api'], true))
            ->reject(fn (array $row): bool => in_array($row['operation_id'] ?? '', $claimed, true))
            ->reject(fn (array $row): bool => in_array($row['operation_id'] ?? '', $reserved, true))
            ->values()
            ->take(15)
            ->all();

        $this->assertNotEmpty($rows);
        $this->fail('NEXT_ROUTE_API_CANDIDATES='.json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
