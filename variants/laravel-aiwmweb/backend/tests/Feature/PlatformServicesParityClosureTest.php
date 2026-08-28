<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlatformServicesParityClosureTest extends TestCase
{
    private const OWNED_DOMAINS = [
        'billing',
        'ai',
        'approvals',
        'email',
        'identity',
        'automation',
        'settings',
        'platform',
    ];

    public function test_owned_domains_have_no_pending_backend_operations(): void
    {
        $payload = $this->reconciliation();

        $unexpected = collect($payload['operations'])
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->reject(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true))
            ->map(fn (array $operation): array => [
                'operation_id' => $operation['operation_id'],
                'domain' => $operation['domain'],
                'kind' => $operation['kind'],
                'route_screen' => $operation['route_screen'],
                'service' => $operation['service'],
                'reason' => $operation['reconciliation']['reason'] ?? null,
            ])
            ->values()
            ->all();

        $this->assertSame(
            [],
            $unexpected,
            'Platform-services closure found backend PENDING rows: '.json_encode($unexpected, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_terminal_backend_operations_keep_countable_destination_and_test_evidence(): void
    {
        $payload = $this->reconciliation();

        $terminal = collect($payload['operations'])
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->reject(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true))
            ->reject(fn (array $operation): bool => $operation['migration_state'] === 'PENDING');

        $this->assertNotEmpty($terminal, 'Expected countable backend parity evidence in platform-service domains.');

        foreach ($terminal as $operation) {
            $operationId = $operation['operation_id'];

            $this->assertNotSame('', trim((string) $operation['laravel_destination']), $operationId.' is missing a Laravel destination.');
            $this->assertNotSame('', trim((string) $operation['acceptance_test']), $operationId.' is missing test evidence.');
            $this->assertTrue((bool) $operation['tenant_owned'], $operationId.' must remain tenant-owned.');
            $this->assertNotSame('', trim((string) ($operation['reconciliation']['source_sha'] ?? '')), $operationId.' is missing exact-SHA evidence.');
        }
    }

    public function test_canonical_pending_inventory_is_limited_to_frontend_routes_and_controls(): void
    {
        $payload = $this->reconciliation();

        $pendingByDomain = collect($payload['operations'])
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->groupBy('domain');

        foreach ($pendingByDomain as $domain => $operations) {
            $kinds = $operations->pluck('kind')->unique()->sort()->values()->all();

            $this->assertSame(
                array_values(array_intersect(['route', 'visible_control'], $kinds)),
                $kinds,
                $domain.' contains a PENDING kind outside frontend route/control ownership.',
            );
        }
    }

    private function reconciliation(): array
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');

        $this->assertFileExists($path);

        return json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
