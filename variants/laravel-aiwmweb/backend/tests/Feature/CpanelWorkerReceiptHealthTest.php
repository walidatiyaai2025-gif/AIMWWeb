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

    public function test_vps_scheduler_rejects_future_heartbeat_instead_of_green(): void
    {
        config(['cpanel.enabled' => false]);
        Cache::store('database')->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() + 10, now()->addMinutes(10));

        $report = app(RuntimeHealthService::class)->ready();

        $this->assertSame('unavailable', $report['checks']['scheduler']['status']);
        $this->assertSame('not_ready', $report['status']);
    }
}
