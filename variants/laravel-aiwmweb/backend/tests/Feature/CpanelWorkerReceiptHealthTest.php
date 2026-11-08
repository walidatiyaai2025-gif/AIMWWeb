<?php

namespace Tests\Feature;

use App\Services\RuntimeHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CpanelWorkerReceiptHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cpanel.enabled' => true,
            'cpanel.cron_enabled' => true,
            'cpanel.preflight_passed' => true,
            'cpanel.ai_enabled' => false,
            'queue.default' => 'database',
            'cache.default' => 'database',
            'session.driver' => 'database',
        ]);
    }

    public function test_enabling_cron_without_execution_does_not_claim_queue_worker_success(): void
    {
        Cache::store('database')->forget(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY);

        $report = app(RuntimeHealthService::class)->ready();

        $this->assertSame('cpanel', $report['profile']);
        $this->assertSame('unavailable', $report['checks']['queue_processing']['status']);
        $this->assertSame('worker_receipt_missing', $report['checks']['queue_processing']['reason_code']);
        $this->assertSame('not_ready', $report['status']);
    }

    public function test_enabled_ai_never_claims_ready_until_worker_and_scheduler_evidence_is_valid(): void
    {
        config(['cpanel.ai_enabled' => true]);
        $store = Cache::store('database');
        $store->forget(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY);
        $store->forget(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY);
        $health = app(RuntimeHealthService::class);

        // Config flags alone never establish a completed worker cycle.
        $this->assertSame('unavailable', $health->ready()['checks']['ai']['status']);

        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 10, now()->addMinutes(10));
        $this->assertSame('unavailable', $health->ready()['checks']['ai']['status']);

        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 10, now()->addMinutes(10));
        $this->assertSame('ok', $health->ready()['checks']['ai']['status']);

        // Database receipts can still be fresh after the configured cache
        // is accidentally switched to a per-request array driver. Neither
        // the AI card nor global readiness may claim success in that state.
        config(['cache.default' => 'array']);
        $cacheBroken = $health->ready();
        $this->assertSame('unavailable', $cacheBroken['checks']['cache']['status']);
        $this->assertSame('ok', $cacheBroken['checks']['queue_processing']['status']);
        $this->assertSame('ok', $cacheBroken['checks']['scheduler']['status']);
        $this->assertSame('unavailable', $cacheBroken['checks']['ai']['status']);
        $this->assertSame('not_ready', $cacheBroken['status']);

        config(['cache.default' => 'database']);
        $this->assertSame('ok', $health->ready()['checks']['ai']['status']);

        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 301, now()->addMinutes(10));
        $this->assertSame('unavailable', $health->ready()['checks']['ai']['status']);

        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 10, now()->addMinutes(10));
        config(['queue.default' => 'sync']);
        $this->assertSame('unavailable', $health->ready()['checks']['ai']['status']);

        config(['queue.default' => 'database', 'cpanel.preflight_passed' => false]);
        $this->assertSame('unavailable', $health->ready()['checks']['ai']['status']);

        config(['cpanel.preflight_passed' => true, 'cpanel.cron_enabled' => false]);
        $this->assertSame('unavailable', $health->ready()['checks']['ai']['status']);

        config(['cpanel.ai_enabled' => false]);
        $this->assertSame('disabled', $health->ready()['checks']['ai']['status']);
    }

    public function test_recent_successful_worker_cycle_receipt_is_allowlisted_and_bounded(): void
    {
        Cache::store('database')->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 15, now()->addMinutes(10));

        $report = app(RuntimeHealthService::class)->ready();
        $queue = $report['checks']['queue_processing'];

        $this->assertSame('ok', $queue['status']);
        $this->assertSame('bounded_worker_cycle_completed', $queue['evidence']);
        $this->assertGreaterThanOrEqual(0, $queue['age_seconds']);
        $this->assertLessThanOrEqual(180, $queue['age_seconds']);
        $this->assertArrayNotHasKey('exception', $queue);
    }

    public function test_stale_and_in_progress_receipts_never_claim_success(): void
    {
        Cache::store('database')->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 300, now()->addMinutes(10));
        $stale = app(RuntimeHealthService::class)->ready()['checks']['queue_processing'];
        $this->assertSame('unavailable', $stale['status']);
        $this->assertSame('worker_receipt_stale', $stale['reason_code']);

        Cache::store('database')->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, 'running', now()->addMinutes(10));
        $running = app(RuntimeHealthService::class)->ready()['checks']['queue_processing'];
        $this->assertSame('unavailable', $running['status']);
        $this->assertSame('worker_receipt_missing', $running['reason_code']);

        Cache::store('database')->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() + 600, now()->addMinutes(10));
        $future = app(RuntimeHealthService::class)->ready()['checks']['queue_processing'];
        $this->assertSame('unavailable', $future['status']);
        $this->assertSame('worker_receipt_invalid', $future['reason_code']);
    }

    public function test_disabled_cron_is_reported_as_disabled_and_never_green(): void
    {
        config(['cpanel.cron_enabled' => false, 'cpanel.ai_enabled' => false]);

        $report = app(RuntimeHealthService::class)->ready();

        $this->assertSame('not_ready', $report['status']);
        $this->assertSame('disabled', $report['checks']['queue_processing']['status']);
        $this->assertSame('cron_disabled', $report['checks']['queue_processing']['reason_code']);
        $this->assertSame('disabled', $report['checks']['scheduler']['status']);
        $this->assertSame('cron_disabled', $report['checks']['scheduler']['reason_code']);
        $this->assertSame('not_required', $report['checks']['redis']['status']);
        $this->assertSame('disabled', $report['checks']['ai']['status']);
    }

    public function test_recent_completed_receipts_are_healthy_but_do_not_override_other_failing_probes(): void
    {
        Cache::store('database')->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 10, now()->addMinutes(10));
        Cache::store('database')->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 10, now()->addMinutes(10));

        $report = app(RuntimeHealthService::class)->ready();

        // Other independent health probes (notably database session setup
        // in a generic SQLite CI environment) may still be unavailable.
        // Two valid receipts must not be asserted to make the whole host ready.
        $this->assertSame('ok', $report['checks']['scheduler']['status']);
        $this->assertSame('ok', $report['checks']['queue_processing']['status']);
        $this->assertSame('not_required', $report['checks']['redis']['status']);

        config(['cpanel.preflight_passed' => false]);
        $pending = app(RuntimeHealthService::class)->ready();
        $this->assertSame('not_ready', $pending['status']);
        $this->assertSame('pending', $pending['hosting_preflight']);
        $this->assertSame('unavailable', $pending['checks']['queue_processing']['status']);
        $this->assertSame('preflight_pending', $pending['checks']['queue_processing']['reason_code']);
        $this->assertSame('unavailable', $pending['checks']['scheduler']['status']);
        $this->assertSame('not_required', $pending['checks']['redis']['status']);
    }

    public function test_inspector_rejects_valid_receipts_when_cpanel_drivers_are_incompatible(): void
    {
        $store = Cache::store('database');
        $schedulerTick = time() - 15;
        $workerTick = time() - 10;
        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, $schedulerTick, now()->addMinutes(10));
        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, $workerTick, now()->addMinutes(10));
        $health = app(RuntimeHealthService::class);

        // A persisted receipt does not establish that this process is using
        // the durable cache and database queue required by the cPanel profile.
        config(['cache.default' => 'array']);
        $badCache = $health->inspectorEvidence();
        $this->assertSame('array', $badCache['runtime_modes']['cache']);
        $this->assertSame('unavailable', $badCache['scheduler_heartbeat']['status']);
        $this->assertSame('cache_driver_mismatch', $badCache['scheduler_heartbeat']['reason_code']);
        $this->assertSame('unavailable', $badCache['worker_cycle']['status']);
        $this->assertSame('cache_driver_mismatch', $badCache['worker_cycle']['reason_code']);

        config(['cache.default' => 'database', 'queue.default' => 'sync']);
        $badQueue = $health->inspectorEvidence();
        $this->assertSame('ok', $badQueue['scheduler_heartbeat']['status']);
        $this->assertSame('unavailable', $badQueue['worker_cycle']['status']);
        $this->assertSame('queue_driver_mismatch', $badQueue['worker_cycle']['reason_code']);
        $this->assertSame('sync', $badQueue['runtime_modes']['queue']);

        config(['queue.default' => 'database']);
        $recovered = $health->inspectorEvidence();
        $this->assertSame('ok', $recovered['scheduler_heartbeat']['status']);
        $this->assertSame('ok', $recovered['worker_cycle']['status']);
        $this->assertSame('bounded_worker_cycle_completed', $recovered['worker_cycle']['evidence']);

        // The Inspector is read-only; changing its status must not replace
        // old receipts, which remain useful as historical execution evidence.
        $this->assertSame($schedulerTick, $store->get(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY));
        $this->assertSame($workerTick, $store->get(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY));
    }

    public function test_inspector_preserves_disabled_cron_and_pending_preflight_precedence_over_driver_errors(): void
    {
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'cpanel.cron_enabled' => false]);
        $health = app(RuntimeHealthService::class);
        $disabled = $health->inspectorEvidence();
        $this->assertSame('disabled', $disabled['scheduler_heartbeat']['status']);
        $this->assertSame('cron_disabled', $disabled['worker_cycle']['reason_code']);

        config(['cpanel.cron_enabled' => true, 'cpanel.preflight_passed' => false]);
        $pending = $health->inspectorEvidence();
        $this->assertSame('preflight_pending', $pending['scheduler_heartbeat']['reason_code']);
        $this->assertSame('preflight_pending', $pending['worker_cycle']['reason_code']);
    }

    public function test_preflight_pending_does_not_turn_existing_worker_receipt_into_green_health(): void
    {
        $store = Cache::store('database');
        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 5, now()->addMinutes(10));
        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 5, now()->addMinutes(10));

        config(['cpanel.preflight_passed' => false]);

        $health = app(RuntimeHealthService::class);
        $readiness = $health->ready();
        $inspector = $health->inspectorEvidence();

        $this->assertSame('not_ready', $readiness['status']);
        $this->assertSame('pending', $readiness['hosting_preflight']);
        $this->assertSame('unavailable', $readiness['checks']['queue_processing']['status']);
        $this->assertSame('preflight_pending', $readiness['checks']['queue_processing']['reason_code']);
        $this->assertSame('unavailable', $readiness['checks']['scheduler']['status']);
        $this->assertSame('unavailable', $inspector['worker_cycle']['status']);
        $this->assertSame('preflight_pending', $inspector['worker_cycle']['reason_code']);

        // The probe is read-only. A stale preflight gate must never delete or
        // rewrite the underlying receipts, which may still be useful for audit.
        $this->assertIsInt($store->get(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY));
        $this->assertIsInt($store->get(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY));
    }

    public function test_future_dated_scheduler_and_worker_receipts_are_not_evidence_of_completed_execution(): void
    {
        $store = Cache::store('database');
        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() + 10, now()->addMinutes(10));
        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 10, now()->addMinutes(10));

        $futureScheduler = app(RuntimeHealthService::class)->ready();
        $this->assertSame('not_ready', $futureScheduler['status']);
        $this->assertSame('unavailable', $futureScheduler['checks']['scheduler']['status']);
        $this->assertSame('ok', $futureScheduler['checks']['queue_processing']['status']);

        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 10, now()->addMinutes(10));
        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() + 10, now()->addMinutes(10));

        $futureWorker = app(RuntimeHealthService::class)->ready();
        $this->assertSame('not_ready', $futureWorker['status']);
        $this->assertSame('ok', $futureWorker['checks']['scheduler']['status']);
        $this->assertSame('unavailable', $futureWorker['checks']['queue_processing']['status']);
        $this->assertSame('worker_receipt_invalid', $futureWorker['checks']['queue_processing']['reason_code']);
    }

    public function test_non_integer_worker_and_scheduler_receipts_never_claim_success(): void
    {
        $store = Cache::store('database');
        $health = app(RuntimeHealthService::class);

        // These are PHP-numeric values but not valid whole-second receipts.
        // A valid recent timestamp on the other source must not mask one.
        foreach ([time() - 10.5, (string) (time() - 10).'e0', true] as $invalid) {
            $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 10, now()->addMinutes(10));
            $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, $invalid, now()->addMinutes(10));

            $worker = $health->ready();
            $this->assertSame('not_ready', $worker['status']);
            $this->assertSame('unavailable', $worker['checks']['queue_processing']['status']);
            $this->assertSame('worker_receipt_missing', $worker['checks']['queue_processing']['reason_code']);
            $this->assertSame('ok', $worker['checks']['scheduler']['status']);

            $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 10, now()->addMinutes(10));
            $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, $invalid, now()->addMinutes(10));

            $scheduler = $health->ready();
            $this->assertSame('not_ready', $scheduler['status']);
            $this->assertSame('unavailable', $scheduler['checks']['scheduler']['status']);
            $this->assertSame('ok', $scheduler['checks']['queue_processing']['status']);
        }
    }

    public function test_vps_scheduler_rejects_numeric_but_non_integer_receipts(): void
    {
        config(['cpanel.enabled' => false]);
        $health = app(RuntimeHealthService::class);

        foreach ([time() - 10.5, (string) (time() - 10).'e0', true] as $invalid) {
            Cache::store('database')->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, $invalid, now()->addMinutes(10));

            $report = $health->ready();
            $this->assertSame('unavailable', $report['checks']['scheduler']['status']);
            $this->assertSame('not_ready', $report['status']);
        }
    }

    public function test_overflowing_digit_receipts_are_rejected_consistently_by_health_and_inspector(): void
    {
        $store = Cache::store('database');
        $overflow = '99999999999999999999999999999999999999999999999999';
        $health = app(RuntimeHealthService::class);

        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 10, now()->addMinutes(10));
        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, $overflow, now()->addMinutes(10));

        $report = $health->ready();
        $inspector = $health->inspectorEvidence();
        $this->assertSame('not_ready', $report['status']);
        $this->assertSame('worker_receipt_missing', $report['checks']['queue_processing']['reason_code']);
        $this->assertSame('receipt_missing', $inspector['worker_cycle']['reason_code']);

        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 10, now()->addMinutes(10));
        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, $overflow, now()->addMinutes(10));
        $this->assertSame('unavailable', $health->ready()['checks']['scheduler']['status']);
        $this->assertSame('receipt_missing', $health->inspectorEvidence()['scheduler_heartbeat']['reason_code']);

        config(['cpanel.enabled' => false]);
        $this->assertSame('unavailable', $health->ready()['checks']['scheduler']['status']);
    }

    public function test_vps_scheduler_rejects_future_heartbeat_instead_of_green(): void
    {
        config(['cpanel.enabled' => false]);
        Cache::store('database')->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() + 10, now()->addMinutes(10));

        $report = app(RuntimeHealthService::class)->ready();

        $this->assertSame('unavailable', $report['checks']['scheduler']['status']);
        $this->assertSame('not_ready', $report['status']);
    }
}
