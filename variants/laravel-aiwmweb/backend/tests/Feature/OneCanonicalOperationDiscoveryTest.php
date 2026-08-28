<?php

namespace Tests\Feature;

use Tests\TestCase;

class OneCanonicalOperationDiscoveryTest extends TestCase
{
    public function test_emit_low_risk_pending_user_runtime_candidates(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $document['operations'] ?? $document['rows'] ?? [];
        $excluded = [
            'AIMW-SYNC-68B372C9FE',
            'AIMW-CONT-EBD53650BC',
            'AIMW-CONT-8EE96B77A8',
            'AIMW-APPR-31A36E339F',
        ];

        $candidates = array_values(array_filter($rows, static function (array $row) use ($excluded): bool {
            if (($row['migration_state'] ?? null) !== 'PENDING') {
                return false;
            }
            if (in_array($row['operation_id'] ?? '', $excluded, true)) {
                return false;
            }
            if (($row['risk'] ?? null) !== 'low') {
                return false;
            }

            return in_array($row['kind'] ?? null, ['route', 'visible_control'], true);
        }));

        usort($candidates, static fn (array $a, array $b): int => strcmp((string) ($a['operation_id'] ?? ''), (string) ($b['operation_id'] ?? '')));

        $projection = array_map(static fn (array $row): array => [
            'operation_id' => $row['operation_id'] ?? null,
            'domain' => $row['domain'] ?? null,
            'kind' => $row['kind'] ?? null,
            'route_screen' => $row['route_screen'] ?? null,
            'current_source' => $row['current_source'] ?? null,
            'visible_control' => $row['visible_control'] ?? null,
            'mutation' => $row['mutation'] ?? null,
            'tenant_owned' => $row['tenant_owned'] ?? null,
            'risk' => $row['risk'] ?? null,
            'verification' => $row['verification'] ?? null,
        ], array_slice($candidates, 0, 25));

        self::fail("ONE_CANONICAL_CANDIDATES\n".json_encode($projection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
