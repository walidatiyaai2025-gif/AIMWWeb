<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlatformServicesParityClosureTest extends TestCase
{
    private const OWNED_DOMAINS = [
        'billing', 'ai', 'approvals', 'email', 'identity', 'automation', 'settings', 'platform',
    ];

    private const BACKEND_PENDING_BASELINE = [
        'ai' => 17,
        'approvals' => 0,
        'automation' => 23,
        'billing' => 3,
        'email' => 6,
        'identity' => 0,
        'platform' => 11,
        'settings' => 0,
    ];

    private const FIRST_BATCH_OPERATION_IDS = [
        'AIMW-AI-972899E060',
        'AIMW-AI-4C0223EC8A',
        'AIMW-AI-C0A95EF796',
        'AIMW-AI-215D0353B1',
        'AIMW-AI-BCE62F8B5A',
        'AIMW-AI-95E4F3045F',
        'AIMW-AI-A2B69A878A',
        'AIMW-BILL-70ADA1CCE0',
        'AIMW-BILL-8CF4E3B705',
        'AIMW-EMAI-351046F246',
        'AIMW-EMAI-AFD4ADAF6A',
        'AIMW-EMAI-AA70A6FF87',
        'AIMW-EMAI-CC3AE08A87',
        'AIMW-EMAI-B2D6A96405',
    ];

    public function test_canonical_generated_snapshot_records_the_exact_backend_pending_baseline(): void
    {
        $pending = $this->pendingBackend();
        $actual = collect(self::OWNED_DOMAINS)
            ->mapWithKeys(fn (string $domain): array => [$domain => $pending->where('domain', $domain)->count()])
            ->sortKeys()
            ->all();

        $expected = collect(self::BACKEND_PENDING_BASELINE)->sortKeys()->all();

        $this->assertSame($expected, $actual);
        $this->assertSame(60, $pending->count());
    }

    public function test_first_batch_targets_are_real_backend_pending_rows_in_the_frozen_snapshot(): void
    {
        $ids = $this->pendingBackend()->pluck('operation_id')->all();

        foreach (self::FIRST_BATCH_OPERATION_IDS as $operationId) {
            $this->assertContains($operationId, $ids, $operationId.' must be a real canonical backend PENDING row at the integration baseline.');
        }
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

    public function test_owned_pending_inventory_is_split_between_backend_and_frontend_work(): void
    {
        $payload = $this->reconciliation();
        $pending = collect($payload['operations'])
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING');

        $backend = $pending->reject(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true));
        $frontend = $pending->filter(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true));

        $this->assertSame(282, $pending->count());
        $this->assertSame(60, $backend->count());
        $this->assertSame(222, $frontend->count());
        $this->assertSame(['route', 'visible_control'], $frontend->pluck('kind')->unique()->sort()->values()->all());
    }

    private function pendingBackend()
    {
        return collect($this->reconciliation()['operations'])
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->reject(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true))
            ->values();
    }

    private function reconciliation(): array
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
