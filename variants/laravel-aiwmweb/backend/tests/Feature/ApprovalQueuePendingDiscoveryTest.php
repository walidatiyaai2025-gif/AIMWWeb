<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApprovalQueuePendingDiscoveryTest extends TestCase
{
    public function test_emit_remaining_pending_approval_rows(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $pending = collect($payload['operations'])
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->filter(fn (array $operation): bool => $operation['domain'] === 'approvals')
            ->map(fn (array $operation): array => [
                'operation_id' => $operation['operation_id'],
                'kind' => $operation['kind'],
                'route_screen' => $operation['route_screen'],
                'visible_control' => $operation['visible_control'],
                'current_source' => $operation['current_source'],
                'mutation' => $operation['mutation'],
                'risk' => $operation['risk'],
            ])
            ->values()
            ->all();

        $this->fail('APPROVAL_PENDING_DISCOVERY='.json_encode($pending, JSON_UNESCAPED_SLASHES));
    }
}
