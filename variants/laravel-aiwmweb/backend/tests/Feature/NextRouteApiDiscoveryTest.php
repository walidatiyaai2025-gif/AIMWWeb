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
            ->filter(fn (array $row): bool => ($row['migration_state'] ?? null) === 'PENDING')
            ->filter(fn (array $row): bool => in_array($row['kind'] ?? null, ['route', 'api'], true))
            ->reject(fn (array $row): bool => in_array($row['operation_id'] ?? '', $claimed, true))
            ->reject(fn (array $row): bool => in_array($row['operation_id'] ?? '', $reserved, true))
            ->values()
            ->take(15)
            ->map(fn (array $row): array => [
                'operation_id' => $row['operation_id'] ?? null,
                'domain' => $row['domain'] ?? null,
                'kind' => $row['kind'] ?? null,
                'route_screen' => $row['route_screen'] ?? null,
                'source_file' => $row['source_file'] ?? null,
                'source_symbol' => $row['source_symbol'] ?? null,
                'mutation' => $row['mutation'] ?? null,
                'tenant_owned' => $row['tenant_owned'] ?? null,
                'risk' => $row['risk'] ?? null,
            ])
            ->all();

        $this->assertNotEmpty($rows);
        $this->fail('NEXT_ROUTE_API_CANDIDATES='.json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
