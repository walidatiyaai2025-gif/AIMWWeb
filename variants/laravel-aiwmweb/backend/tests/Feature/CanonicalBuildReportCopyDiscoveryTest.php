<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

final class CanonicalBuildReportCopyDiscoveryTest extends TestCase
{
    public function test_dump_independently_closable_pending_reads(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, flags: JSON_THROW_ON_ERROR);
        $candidates = [];

        $walk = function (mixed $value) use (&$walk, &$candidates): void {
            if (! is_array($value)) {
                return;
            }

            $operationId = $value['operation_id'] ?? null;
            $state = strtoupper((string) ($value['migration_state'] ?? $value['reconciliation']['decision'] ?? ''));
            $kind = strtolower((string) ($value['kind'] ?? ''));
            $mutation = (bool) ($value['mutation'] ?? false);

            if (is_string($operationId) && $state === 'PENDING' && ! $mutation && in_array($kind, ['api', 'route'], true)) {
                $candidates[] = [
                    'operation_id' => $operationId,
                    'kind' => $kind,
                    'domain' => $value['domain'] ?? null,
                    'route_screen' => $value['route_screen'] ?? null,
                    'visible_control' => $value['visible_control'] ?? null,
                    'current_source' => $value['current_source'] ?? null,
                    'service' => $value['service'] ?? null,
                    'external_dependency' => $value['external_dependency'] ?? null,
                    'tenant_owned' => $value['tenant_owned'] ?? null,
                    'risk' => $value['risk'] ?? null,
                    'reason' => $value['reconciliation']['reason'] ?? null,
                ];
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        $walk($payload);

        throw new RuntimeException(json_encode([
            'count' => count($candidates),
            'candidates' => array_slice($candidates, 0, 80),
            'totals' => $payload['totals'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
