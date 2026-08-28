<?php

namespace Tests\Feature;

use Tests\TestCase;

class RouteApiTerminalityInventoryTest extends TestCase
{
    public function test_emit_pending_route_api_inventory_for_closure(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($path);

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = collect($payload['operations'])
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->filter(fn (array $operation): bool => in_array($operation['kind'], ['route', 'api'], true))
            ->map(fn (array $operation): array => [
                'operation_id' => $operation['operation_id'],
                'domain' => $operation['domain'],
                'kind' => $operation['kind'],
                'route_screen' => $operation['route_screen'],
                'visible_control' => $operation['visible_control'],
                'current_source' => $operation['current_source'],
                'mutation' => $operation['mutation'],
                'approval' => $operation['approval'],
                'tenant_owned' => $operation['tenant_owned'],
                'risk' => $operation['risk'],
            ])
            ->values()
            ->all();

        fwrite(STDERR, "\nROUTE_API_INVENTORY_BEGIN\n");
        fwrite(STDERR, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nROUTE_API_INVENTORY_END\n");

        $this->fail('Diagnostic inventory emitted: '.count($rows).' pending route/api rows.');
    }
}
