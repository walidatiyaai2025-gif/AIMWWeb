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

    private const COMPOSED_ROUTE_CLOSURES_IN_OWNED_DOMAINS = [
        'AIMW-EMAI-2E95AF6C05',
        'AIMW-EMAI-78352CD34E',
    ];

    private const STRICT_TENANT_NEUTRAL_BACKEND_OPERATIONS = [
        'AIMW-PLAT-04D5067C61' => 'SetLanguage',
        'AIMW-PLAT-17E3F2B4ED' => 'GetLanguage',
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
            $this->assertTrue(
                (bool) $operation['tenant_owned']
                    || $this->hasStrictTenantSelectedApiEvidence($operation)
                    || $this->hasStrictTenantNeutralBackendEvidence($operation),
                $operationId.' must remain tenant-owned, carry the strict explicit tenant-selected API security contract, or be an explicitly proven tenant-neutral local operation.',
            );
            $this->assertNotSame('', trim((string) ($operation['reconciliation']['source_sha'] ?? '')), $operationId.' is missing exact-SHA evidence.');
        }
    }

    public function test_owned_pending_inventory_can_only_shrink_while_frontend_truthfulness_remains_explicit(): void
    {
        $payload = $this->reconciliation();
        $operations = collect($payload['operations']);
        $pending = $operations
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING');

        $backend = $pending->reject(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true));
        $frontend = $pending->filter(fn (array $operation): bool => in_array($operation['kind'], ['visible_control', 'route'], true));
        $visibleControls = $operations
            ->filter(fn (array $operation): bool => in_array($operation['domain'], self::OWNED_DOMAINS, true))
            ->filter(fn (array $operation): bool => $operation['kind'] === 'visible_control');
        $terminalStates = ['PORTED', 'ADAPTED', 'VERIFIED_UNAVAILABLE_EXTERNAL'];
        $terminalVisibleControls = $visibleControls
            ->filter(fn (array $operation): bool => in_array($operation['migration_state'], $terminalStates, true));
        $nonTerminalVisibleControls = $visibleControls
            ->reject(fn (array $operation): bool => in_array($operation['migration_state'], $terminalStates, true));

        $this->assertLessThanOrEqual(282, $pending->count());
        $this->assertLessThanOrEqual(60, $backend->count());
        $this->assertLessThanOrEqual(220, $frontend->count(), 'Owned frontend/route PENDING inventory may only shrink as governed closures land.');
        $this->assertTrue(
            $nonTerminalVisibleControls->every(fn (array $operation): bool => $operation['migration_state'] === 'PENDING'),
            'Owned visible controls may leave PENDING only through a terminal classification.',
        );
        $this->assertSame(['route', 'visible_control'], $frontend->pluck('kind')->unique()->sort()->values()->all());

        foreach ($terminalVisibleControls as $operation) {
            $operationId = $operation['operation_id'];
            $this->assertNotSame('', trim((string) $operation['laravel_destination']), $operationId.' is missing a governed frontend destination.');
            $this->assertNotSame('', trim((string) $operation['acceptance_test']), $operationId.' is missing focused acceptance evidence.');
            $this->assertNotSame('', trim((string) ($operation['reconciliation']['source_sha'] ?? '')), $operationId.' is missing exact-SHA evidence.');
            $this->assertSame('focused_closure_contract', $operation['reconciliation']['evidence_mode'] ?? null, $operationId.' lacks the focused closure evidence contract.');
            $this->assertNotSame('', trim((string) ($operation['reconciliation']['evidence_path'] ?? '')), $operationId.' is missing its closure-evidence path.');
        }

        foreach (self::COMPOSED_ROUTE_CLOSURES_IN_OWNED_DOMAINS as $operationId) {
            $operation = $operations->firstWhere('operation_id', $operationId);
            $this->assertNotNull($operation);
            $this->assertSame('route', $operation['kind']);
            $this->assertSame('ADAPTED', $operation['migration_state']);
            $this->assertSame('explicit_route_contract', $operation['reconciliation']['evidence_mode'] ?? null);
        }
    }

    private function hasStrictTenantSelectedApiEvidence(array $operation): bool
    {
        $reconciliation = $operation['reconciliation'] ?? [];
        if (($reconciliation['evidence_mode'] ?? null) !== 'explicit_route_api_contract') {
            return false;
        }

        $signals = $reconciliation['signals'] ?? [];
        foreach ([
            'middleware:auth',
            'tenant:selected',
            'authorization:TenantAuthorizer',
            'test:401',
            'test:403',
            'test:404',
            'test:409/conflict',
        ] as $requiredSignal) {
            if (! in_array($requiredSignal, $signals, true)) {
                return false;
            }
        }

        return true;
    }

    private function hasStrictTenantNeutralBackendEvidence(array $operation): bool
    {
        $operationId = $operation['operation_id'] ?? null;
        $expectedMethod = self::STRICT_TENANT_NEUTRAL_BACKEND_OPERATIONS[$operationId] ?? null;
        if ($expectedMethod === null) {
            return false;
        }

        if (($operation['tenant_owned'] ?? true) !== false
            || ($operation['mutation'] ?? true) !== false
            || ($operation['external_dependency'] ?? null) !== 'none'
            || ($operation['native_wp_rest'] ?? true) !== false
            || ($operation['connector_required'] ?? true) !== false
            || ($operation['risk'] ?? null) !== 'low') {
            return false;
        }

        if (($operation['service'] ?? null) !== 'LanguagePreferenceService'
            || ($operation['visible_control'] ?? null) !== $expectedMethod) {
            return false;
        }

        $signals = $operation['reconciliation']['signals'] ?? [];

        return in_array('method:'.$expectedMethod, $signals, true)
            && in_array('token:language', $signals, true)
            && in_array('token:preference', $signals, true);
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
