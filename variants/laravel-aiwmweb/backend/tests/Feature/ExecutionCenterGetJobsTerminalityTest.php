<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterServiceAdapter;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ExecutionCenterGetJobsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-035FFB3624';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_execution_center_get_jobs_identity_is_bound_to_the_laravel_adapter(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('service:ExecutionCenterService', $operation['route_screen']);
        $this->assertSame('ExecutionCenterService', $operation['service']);
        $this->assertSame('GetJobs', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterServiceAdapter::OPERATION_ID);
    }

    public function test_get_jobs_composes_both_existing_execution_ledgers_for_the_owner_newest_first(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        $oldControl = $this->insertControlPlaneExecution($tenant->id, $owner->id, 'sync.full', '2026-09-10 08:00:00');
        $middleApproval = $this->insertApprovalExecution($tenant->id, $owner->id, 'running', '2026-09-10 09:00:00');
        $newControl = $this->insertControlPlaneExecution($tenant->id, $owner->id, 'report.export', '2026-09-10 10:00:00');

        $jobs = app(ExecutionCenterServiceAdapter::class)->getJobs($owner->id);

        $this->assertCount(3, $jobs);
        $this->assertSame([$newControl, $middleApproval, $oldControl], array_column($jobs, 'job_id'));
        $this->assertSame(['operation_execution', 'approval_execution', 'operation_execution'], array_column($jobs, 'ledger'));
        $this->assertSame([$owner->id, $owner->id, $owner->id], array_column($jobs, 'owner_user_id'));
    }

    public function test_cross_tenant_and_other_owner_jobs_are_404_equivalent_absent_from_the_result(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $alphaOwned = $this->insertControlPlaneExecution($alpha->id, $owner->id, 'sync.alpha', '2026-09-10 08:00:00');
        $sameTenantOtherOwner = $this->insertControlPlaneExecution($alpha->id, $other->id, 'sync.other', '2026-09-10 09:00:00');
        $foreignTenantSameOwner = $this->insertControlPlaneExecution($beta->id, $owner->id, 'sync.beta', '2026-09-10 10:00:00');
        $foreignApproval = $this->insertApprovalExecution($beta->id, $owner->id, 'queued', '2026-09-10 11:00:00');

        app(TenantContext::class)->activate($alpha);
        $jobIds = array_column(app(ExecutionCenterServiceAdapter::class)->getJobs($owner->id), 'job_id');

        $this->assertSame([$alphaOwned], $jobIds);
        $this->assertNotContains($sameTenantOtherOwner, $jobIds);
        $this->assertNotContains($foreignTenantSameOwner, $jobIds);
        $this->assertNotContains($foreignApproval, $jobIds);
    }

    public function test_get_jobs_is_read_only_and_does_not_expose_raw_payload_or_secret_failure_values(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        $jobId = (string) Str::uuid();
        DB::table('operation_executions')->insert([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $owner->id,
            'type' => 'report.export',
            'correlation_id' => $jobId,
            'status' => 'failed',
            'payload' => json_encode(['api_token' => 'payload-secret'], JSON_THROW_ON_ERROR),
            'result' => json_encode(['authorization' => 'result-secret'], JSON_THROW_ON_ERROR),
            'failure' => 'provider token=failure-secret timed out',
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-10 10:00:00',
        ]);

        $before = DB::table('operation_executions')->where('tenant_id', $tenant->id)->first();
        $jobs = app(ExecutionCenterServiceAdapter::class)->getJobs($owner->id);
        $after = DB::table('operation_executions')->where('tenant_id', $tenant->id)->first();

        $this->assertCount(1, $jobs);
        $this->assertSame($jobId, $jobs[0]['job_id']);
        $this->assertSame('provider token=[REDACTED] timed out', $jobs[0]['error']);
        $this->assertArrayNotHasKey('payload', $jobs[0]);
        $this->assertArrayNotHasKey('result', $jobs[0]);
        $encoded = json_encode($jobs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('payload-secret', $encoded);
        $this->assertStringNotContainsString('result-secret', $encoded);
        $this->assertStringNotContainsString('failure-secret', $encoded);
        $this->assertEquals($before, $after);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_invalid_owner_identity_is_rejected_before_any_query(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);

        $this->expectException(InvalidArgumentException::class);
        app(ExecutionCenterServiceAdapter::class)->getJobs(0);
    }

    public function test_inactive_tenant_context_fails_closed(): void
    {
        $owner = User::factory()->create();
        app(TenantContext::class)->forget();

        $this->expectException(LogicException::class);
        app(ExecutionCenterServiceAdapter::class)->getJobs($owner->id);
    }

    private function insertControlPlaneExecution(int $tenantId, int $ownerUserId, string $type, string $createdAt): string
    {
        $correlationId = (string) Str::uuid();
        DB::table('operation_executions')->insert([
            'tenant_id' => $tenantId,
            'requested_by_user_id' => $ownerUserId,
            'type' => $type,
            'correlation_id' => $correlationId,
            'status' => 'queued',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $correlationId;
    }

    private function insertApprovalExecution(int $tenantId, int $ownerUserId, string $status, string $createdAt): string
    {
        $siteId = (int) DB::table('sites')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Execution Center fixture '.Str::random(8),
            'url' => 'https://'.Str::lower(Str::random(12)).'.example.test',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $approvalId = (int) DB::table('approvals')->insertGetId([
            'tenant_id' => $tenantId,
            'suggestion_id' => null,
            'actor_user_id' => $ownerUserId,
            'status' => 'APPROVED',
            'before_state' => json_encode(['title' => 'before'], JSON_THROW_ON_ERROR),
            'proposed_state' => json_encode(['title' => 'after'], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $operationId = (string) Str::uuid();
        DB::table('executions')->insert([
            'operation_id' => $operationId,
            'request_id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'approval_id' => $approvalId,
            'actor_user_id' => $ownerUserId,
            'status' => $status,
            'attempts' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $operationId;
    }
}
