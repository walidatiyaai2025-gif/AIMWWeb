<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterServiceAdapter;
use App\Models\Execution;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ExecutionCenterGetActivitiesTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-97A9F6A324';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_execution_center_get_activities_identity_is_bound_to_the_laravel_adapter(): void
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
        $this->assertSame('GetActivities', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterServiceAdapter::GET_ACTIVITIES_OPERATION_ID);
    }

    public function test_get_activities_composes_existing_activity_ledgers_for_owned_jobs_newest_first(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        [$controlExecutionId, $controlJobId] = $this->insertControlExecution($tenant->id, $owner->id, 'sync.full', '2026-09-10 08:00:00');
        $this->insertOperationLog($tenant->id, $controlExecutionId, $controlJobId, 'info', 'Control activity', '2026-09-10 09:00:00');

        [$approvalExecutionId, $approvalJobId] = $this->insertApprovalExecution($tenant->id, $owner->id, '2026-09-10 08:30:00');
        $this->insertExecutionAudit($tenant->id, $owner->id, $approvalExecutionId, 'execution.failed', 'Error', 'External activity', '2026-09-10 10:00:00');

        $activities = app(ExecutionCenterServiceAdapter::class)->getActivities($owner->id, 30);

        $this->assertCount(2, $activities);
        $this->assertSame([$approvalJobId, $controlJobId], array_column($activities, 'job_id'));
        $this->assertSame(['audit_event', 'operation_log'], array_column($activities, 'ledger'));
        $this->assertSame(['Error', 'Info'], array_column($activities, 'level'));
        $this->assertSame(['External activity', 'Control activity'], array_column($activities, 'message'));
    }

    public function test_cross_tenant_and_other_owner_activity_is_absent_even_when_event_actor_is_forged(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $owner = User::factory()->create();
        $other = User::factory()->create();

        [$ownedExecutionId, $ownedJobId] = $this->insertControlExecution($alpha->id, $owner->id, 'sync.alpha', '2026-09-10 08:00:00');
        $this->insertOperationLog($alpha->id, $ownedExecutionId, $ownedJobId, 'info', 'owned', '2026-09-10 08:10:00');

        [$otherExecutionId, $otherJobId] = $this->insertControlExecution($alpha->id, $other->id, 'sync.other', '2026-09-10 09:00:00');
        $this->insertOperationLog($alpha->id, $otherExecutionId, $otherJobId, 'info', 'other', '2026-09-10 09:10:00');

        [$foreignExecutionId, $foreignJobId] = $this->insertControlExecution($beta->id, $owner->id, 'sync.beta', '2026-09-10 10:00:00');
        $this->insertOperationLog($beta->id, $foreignExecutionId, $foreignJobId, 'info', 'foreign', '2026-09-10 10:10:00');

        [$otherApprovalId, $otherApprovalJobId] = $this->insertApprovalExecution($alpha->id, $other->id, '2026-09-10 11:00:00');
        // Deliberately forge the audit actor to the requested owner. The execution ownership
        // whitelist must still reject this activity.
        $this->insertExecutionAudit($alpha->id, $owner->id, $otherApprovalId, 'execution.failed', 'Error', 'forged actor', '2026-09-10 11:10:00');

        app(TenantContext::class)->activate($alpha);
        $jobIds = array_column(app(ExecutionCenterServiceAdapter::class)->getActivities($owner->id), 'job_id');

        $this->assertSame([$ownedJobId], $jobIds);
        $this->assertNotContains($otherJobId, $jobIds);
        $this->assertNotContains($foreignJobId, $jobIds);
        $this->assertNotContains($otherApprovalJobId, $jobIds);
    }

    public function test_get_activities_clamps_take_redacts_messages_and_is_read_only(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        [$executionId, $jobId] = $this->insertControlExecution($tenant->id, $owner->id, 'report.export', '2026-09-10 08:00:00');
        $this->insertOperationLog($tenant->id, $executionId, $jobId, 'warning', 'first token=top-secret', '2026-09-10 09:00:00');
        $this->insertOperationLog($tenant->id, $executionId, $jobId, 'error', 'second api_key=hidden-value', '2026-09-10 10:00:00');

        $beforeLogs = DB::table('operation_logs')->orderBy('id')->get()->toJson();
        $beforeAudits = DB::table('audit_events')->orderBy('id')->get()->toJson();
        $activities = app(ExecutionCenterServiceAdapter::class)->getActivities($owner->id, 0);
        $afterLogs = DB::table('operation_logs')->orderBy('id')->get()->toJson();
        $afterAudits = DB::table('audit_events')->orderBy('id')->get()->toJson();

        $this->assertCount(1, $activities);
        $this->assertSame($jobId, $activities[0]['job_id']);
        $this->assertSame('Error', $activities[0]['level']);
        $this->assertSame('second api_key=[REDACTED]', $activities[0]['message']);
        $this->assertStringNotContainsString('hidden-value', json_encode($activities, JSON_THROW_ON_ERROR));
        $this->assertSame($beforeLogs, $afterLogs);
        $this->assertSame($beforeAudits, $afterAudits);
    }

    public function test_invalid_owner_and_missing_tenant_context_fail_closed(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);

        try {
            app(ExecutionCenterServiceAdapter::class)->getActivities(0);
            $this->fail('Invalid owner identity must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $owner = User::factory()->create();
        app(TenantContext::class)->forget();

        $this->expectException(LogicException::class);
        app(ExecutionCenterServiceAdapter::class)->getActivities($owner->id);
    }

    /** @return array{0:int,1:string} */
    private function insertControlExecution(int $tenantId, int $ownerUserId, string $type, string $createdAt): array
    {
        $correlationId = (string) Str::uuid();
        $id = (int) DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenantId,
            'requested_by_user_id' => $ownerUserId,
            'type' => $type,
            'correlation_id' => $correlationId,
            'status' => 'running',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return [$id, $correlationId];
    }

    private function insertOperationLog(int $tenantId, int $executionId, string $correlationId, string $level, string $message, string $occurredAt): void
    {
        DB::table('operation_logs')->insert([
            'tenant_id' => $tenantId,
            'operation_execution_id' => $executionId,
            'correlation_id' => $correlationId,
            'level' => $level,
            'message' => $message,
            'context' => json_encode(['source' => 'test.runtime'], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @return array{0:int,1:string} */
    private function insertApprovalExecution(int $tenantId, int $ownerUserId, string $createdAt): array
    {
        $siteId = (int) DB::table('sites')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Execution activity fixture '.Str::random(8),
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
        $executionId = (int) DB::table('executions')->insertGetId([
            'operation_id' => $operationId,
            'request_id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'approval_id' => $approvalId,
            'actor_user_id' => $ownerUserId,
            'status' => 'running',
            'attempts' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return [$executionId, $operationId];
    }

    private function insertExecutionAudit(int $tenantId, int $actorUserId, int $executionId, string $event, string $level, string $message, string $occurredAt): void
    {
        DB::table('audit_events')->insert([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'subject_type' => Execution::class,
            'subject_id' => (string) $executionId,
            'metadata' => json_encode(['level' => $level, 'message' => $message], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
        ]);
    }
}
