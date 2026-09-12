<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ExecutionJobConfiguration;
use App\Jobs\ExecutionJobStore;
use App\Models\Site;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class ExecutionJobStoreParityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-EEFDAB3DF8';

    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantContext = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tenantContext->forget();
        parent::tearDown();
    }

    public function test_operation_is_linked_to_the_live_canonical_background_job_contract(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('background_job', $operation['kind']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('ExecutionJobStore', $operation['background_job']);
        $this->assertSame(
            'src/AIWordPressManager.Persistence/Jobs/ExecutionJobStore.cs',
            $operation['current_source'],
        );
        $this->assertSame(self::OPERATION_ID, ExecutionJobStore::OPERATION_ID);
    }

    public function test_start_report_complete_and_recent_projection_match_canonical_lifecycle(): void
    {
        $tenantId = $this->createTenant('tenant-a');
        $this->tenantContext->activate(Tenant::query()->findOrFail($tenantId));
        $site = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.example']);
        $store = app(ExecutionJobStore::class);

        Carbon::setTestNow('2026-09-11 10:00:00 UTC');
        $first = $store->start($site->id, 'ContentSync');
        $this->assertTrue(Str::isUuid($first));
        $this->assertDatabaseHas('executions', [
            'tenant_id' => $tenantId,
            'site_id' => $site->id,
            'operation_id' => $first,
            'approval_id' => null,
            'actor_user_id' => null,
            'job_type' => 'ContentSync',
            'status' => 'Running',
            'progress_percent' => 0,
            'current_step' => 'Starting',
        ]);

        Carbon::setTestNow('2026-09-11 10:01:00 UTC');
        $store->report($first, 125, 'Publishing');
        $job = $store->get($first);
        $this->assertNotNull($job);
        $this->assertSame(100, $job['progress_percent']);
        $this->assertSame('Publishing', $job['current_step']);
        $this->assertSame($site->id, $job['site_id']);
        $this->assertSame('Alpha', $job['site_name']);
        $this->assertNotSame('', $job['started_at_utc']);

        Carbon::setTestNow('2026-09-11 10:02:00 UTC');
        $store->complete($first);
        $job = $store->get($first);
        $this->assertNotNull($job);
        $this->assertSame('Completed', $job['status']);
        $this->assertSame(100, $job['progress_percent']);
        $this->assertSame('Completed', $job['current_step']);
        $this->assertNotNull($job['completed_at_utc']);

        Carbon::setTestNow('2026-09-11 10:03:00 UTC');
        $second = $store->start($site->id, 'MediaScan');
        $recent = $store->getRecent(null, 0); // Canonical clamp: minimum take is one.

        $this->assertCount(1, $recent);
        $this->assertSame($second, $recent[0]['id']);
        $this->assertSame($site->id, $recent[0]['site_id']);
        $this->assertSame('Alpha', $recent[0]['site_name']);
        $this->assertSame('MediaScan', $recent[0]['job_type']);
        $this->assertSame('Running', $recent[0]['status']);
        $this->assertNotSame('', $recent[0]['updated_at_utc']);

        $filtered = $store->getRecent($site->id, 10);
        $this->assertCount(2, $filtered);
    }

    public function test_failure_and_cancel_terminal_states_preserve_redaction_and_completion_evidence(): void
    {
        $tenantId = $this->createTenant('tenant-terminal');
        $this->tenantContext->activate(Tenant::query()->findOrFail($tenantId));
        $site = Site::query()->create(['name' => 'Terminal', 'url' => 'https://terminal.example']);
        $store = app(ExecutionJobStore::class);

        $failed = $store->start($site->id, 'FailureProbe');
        $store->fail($failed, 'Authorization: Bearer top-secret token=other-secret upstream timeout');
        $failedJob = $store->get($failed);

        $this->assertNotNull($failedJob);
        $this->assertSame('Failed', $failedJob['status']);
        $this->assertSame('Failed', $failedJob['current_step']);
        $this->assertNotNull($failedJob['completed_at_utc']);
        $this->assertStringNotContainsString('top-secret', (string) $failedJob['error_details']);
        $this->assertStringNotContainsString('other-secret', (string) $failedJob['error_details']);
        $this->assertStringContainsString('[REDACTED]', (string) $failedJob['error_details']);

        $cancelled = $store->start($site->id, 'CancelProbe');
        $store->cancel($cancelled);
        $cancelledJob = $store->get($cancelled);

        $this->assertNotNull($cancelledJob);
        $this->assertSame('Cancelled', $cancelledJob['status']);
        $this->assertSame('Cancelled', $cancelledJob['current_step']);
        $this->assertNotNull($cancelledJob['completed_at_utc']);
        $this->assertNotNull(
            DB::table('executions')->where('operation_id', $cancelled)->value('cancelled_at'),
        );
    }

    public function test_invalid_missing_and_cross_tenant_access_fail_closed_without_mutating_foreign_jobs(): void
    {
        $tenantA = $this->createTenant('tenant-isolation-a');
        $tenantB = $this->createTenant('tenant-isolation-b');

        $this->tenantContext->activate(Tenant::query()->findOrFail($tenantA));
        $siteA = Site::query()->create(['name' => 'A', 'url' => 'https://a.example']);
        $store = app(ExecutionJobStore::class);
        $jobId = $store->start($siteA->id, 'IsolationProbe');

        try {
            $store->report($jobId, 50, str_repeat('x', ExecutionJobConfiguration::CURRENT_STEP_MAX_LENGTH + 1));
            $this->fail('Invalid current-step state must fail closed.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, $store->get($jobId)['progress_percent'] ?? null);

        $this->tenantContext->activate(Tenant::query()->findOrFail($tenantB));
        $siteB = Site::query()->create(['name' => 'B', 'url' => 'https://b.example']);
        $this->assertNotNull($siteB);
        $this->assertNull($store->get($jobId), 'Tenant B must not read Tenant A execution jobs.');
        $this->assertSame([], $store->getRecent($siteA->id, 10));

        try {
            $store->report($jobId, 88, 'Foreign mutation must fail like a missing job');
            $this->fail('Tenant B must not mutate Tenant A execution jobs.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $store->start($siteA->id, 'CrossTenantProbe');
            $this->fail('Starting a job against another tenant site must fail closed.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $store->cancel((string) Str::uuid());
            $this->fail('Canonical SingleAsync mutation lookup must fail for a missing job.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->tenantContext->activate(Tenant::query()->findOrFail($tenantA));
        $job = $store->get($jobId);
        $this->assertNotNull($job);
        $this->assertSame(0, $job['progress_percent']);
        $this->assertSame('Starting', $job['current_step']);
    }

    private function createTenant(string $slug): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
