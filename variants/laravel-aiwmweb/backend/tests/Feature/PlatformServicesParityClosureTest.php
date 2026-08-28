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

    public function test_composed_reconciliation_never_regresses_the_frozen_backend_pending_baseline(): void
    {
        $pending = $this->pendingBackend();
        $actual = collect(self::OWNED_DOMAINS)
            ->mapWithKeys(fn (string $domain): array => [$domain => $pending->where('domain', $domain)->count()])
            ->sortKeys()
            ->all();

        foreach (self::BACKEND_PENDING_BASELINE as $domain => $baseline) {
            $this->assertLessThanOrEqual(
                $baseline,
                $actual[$domain] ?? 0,
                $domain.' backend PENDING inventory regressed above the frozen PR #279 integration baseline.',
            );
        }

        $this->assertLessThanOrEqual(60, $pending->count());
    }

    public function test_first_batch_targets_remain_pending_or_have_terminal_countable_evidence_after_composition(): void
    {
        $operations = collect($this->reconciliation()['operations'])->keyBy('operation_id');

        foreach (self::FIRST_BATCH_OPERATION_IDS as $operationId) {
            $this->assertTrue($operations->has($operationId), $operationId.' disappeared from the canonical 931-operation ledger.');

            $operation = $operations->get($operationId);
            if ($operation['migration_state'] === 'PENDING') {
                continue;
            }

            $this->assertContains(
                $operation['migration_state'],
                ['PORTED', 'ADAPTED', 'VERIFIED_UNAVAILABLE_EXTERNAL'],
                $operationId.' may leave the frozen PENDING baseline only through a terminal classification.',
            );

            if (in_array($operation['migration_state'], ['PORTED', 'ADAPTED'], true)) {
                $this->assertNotSame('', trim((string) $operation['laravel_destination']), $operationId.' is missing a Laravel destination.');
                $this->assertNotSame('', trim((string) $operation['acceptance_test']), $operationId.' is missing test evidence.');
                $this->assertNotSame('', trim((string) ($operation['reconciliation']['source_sha'] ?? '')), $operationId.' is missing exact-SHA evidence.');
            }
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

    public function test_owned_pending_inventory_can_only_shrink_while_frontend_truthfulness_remains_explicit(): void
    {
        $payload = $this->reconciliation();
        $pending = collect($payload['operations'])
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING');

        $backend = $pending->reject(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true));
        $frontend = $pending->filter(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true));

        $this->assertLessThanOrEqual(282, $pending->count());
        $this->assertLessThanOrEqual(60, $backend->count());
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
