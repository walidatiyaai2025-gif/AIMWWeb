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
        config(['cpanel.cron_enabled' => false]);

        $queue = app(RuntimeHealthService::class)->ready()['checks']['queue_processing'];

        $this->assertSame('disabled', $queue['status']);
        $this->assertSame('cron_disabled', $queue['reason_code']);
    }
}
