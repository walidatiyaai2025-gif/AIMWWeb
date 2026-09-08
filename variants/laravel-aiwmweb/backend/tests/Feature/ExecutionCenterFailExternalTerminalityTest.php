<?php

namespace Tests\Feature;

use App\Execution\ExternalExecutionFailureService;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ExecutionCenterFailExternalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-D1F233AB61';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_execution_center_fail_external_identity_is_bound_to_the_laravel_adapter(): void
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
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('service:ExecutionCenterService', $operation['route_screen']);
        $this->assertSame('FailExternal', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(self::OPERATION_ID, ExternalExecutionFailureService::OPERATION_ID);
    }

    public function test_fail_external_transitions_only_the_owned_running_execution_and_records_redacted_error_activity(): void
    {
        $clock = CarbonImmutable::parse('2026-09-08 13:45:00', 'UTC');
        CarbonImmutable::setTestNow($clock);

        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        $approvalId = $this->insertApproval($tenant->id, $owner->id, 'APPROVED');
        $operationId = (string) Str::uuid();
        $executionId = $this->insertExecution($tenant->id, $owner->id, $approvalId, 'running', $operationId);

        $changed = app(ExternalExecutionFailureService::class)->failExternal(
            $operationId,
            $owner->id,
            '  provider timeout token=super-secret  ',
        );

        $this->assertTrue($changed);
        $this->assertDatabaseHas('executions', [
            'id' => $executionId,
            'tenant_id' => $tenant->id,
            'actor_user_id' => $owner->id,
            'status' => 'failed',
            'completed_at' => $clock->format('Y-m-d H:i:s'),
            'failure' => 'provider timeout token=[REDACTED]',
        ]);
        $this->assertDatabaseHas('approvals', [
            'id' => $approvalId,
            'tenant_id' => $tenant->id,
            'status' => 'APPROVED',
        ]);

        $activity = AuditEvent::query()
            ->where('event', 'execution.failed')
            ->where('subject_type', \App\Models\Execution::class)
            ->where('subject_id', (string) $executionId)
            ->firstOrFail();

        $this->assertSame($owner->id, $activity->actor_user_id);
        $this->assertSame(self::OPERATION_ID, $activity->metadata['canonical_operation']);
        $this->assertSame('Error', $activity->metadata['level']);
        $this->assertSame('provider timeout token=[REDACTED]', $activity->metadata['message']);
        $this->assertSame($operationId, $activity->metadata['operation_id']);
        $this->assertStringNotContainsString('super-secret', json_encode($activity->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_blank_error_uses_the_canonical_external_failure_fallback(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        $approvalId = $this->insertApproval($tenant->id, $owner->id, 'APPROVED');
        $operationId = (string) Str::uuid();
        $executionId = $this->insertExecution($tenant->id, $owner->id, $approvalId, 'running', $operationId);

        $this->assertTrue(app(ExternalExecutionFailureService::class)->failExternal($operationId, $owner->id, '   '));
        $this->assertDatabaseHas('executions', [
            'id' => $executionId,
            'status' => 'failed',
            'failure' => ExternalExecutionFailureService::DEFAULT_ERROR,
        ]);
        $this->assertSame(
            ExternalExecutionFailureService::DEFAULT_ERROR,
            AuditEvent::query()->where('event', 'execution.failed')->firstOrFail()->metadata['message'],
        );
    }

    public function test_wrong_owner_and_non_running_state_are_no_ops_without_error_activity(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        $approvalId = $this->insertApproval($tenant->id, $owner->id, 'APPROVED');
        $runningOperationId = (string) Str::uuid();
        $runningExecutionId = $this->insertExecution($tenant->id, $owner->id, $approvalId, 'running', $runningOperationId);

        $otherApprovalId = $this->insertApproval($tenant->id, $owner->id, 'APPROVED');
        $queuedOperationId = (string) Str::uuid();
        $queuedExecutionId = $this->insertExecution($tenant->id, $owner->id, $otherApprovalId, 'queued', $queuedOperationId);

        $service = app(ExternalExecutionFailureService::class);
        $this->assertFalse($service->failExternal($runningOperationId, $otherUser->id, 'wrong owner'));
        $this->assertFalse($service->failExternal($queuedOperationId, $owner->id, 'wrong state'));

        $this->assertDatabaseHas('executions', ['id' => $runningExecutionId, 'status' => 'running', 'failure' => null]);
        $this->assertDatabaseHas('executions', ['id' => $queuedExecutionId, 'status' => 'queued', 'failure' => null]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_foreign_tenant_operation_is_404_equivalent_fail_closed_and_preserves_the_foreign_execution(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $owner = User::factory()->create();

        app(TenantContext::class)->activate($beta);
        $approvalId = $this->insertApproval($beta->id, $owner->id, 'APPROVED');
        $operationId = (string) Str::uuid();
        $executionId = $this->insertExecution($beta->id, $owner->id, $approvalId, 'running', $operationId);

        app(TenantContext::class)->activate($alpha);
        $changed = app(ExternalExecutionFailureService::class)->failExternal($operationId, $owner->id, 'cross-tenant attempt');

        $this->assertFalse($changed, 'Foreign tenant execution must be treated as 404-equivalent not found.');
        $this->assertDatabaseHas('executions', [
            'id' => $executionId,
            'tenant_id' => $beta->id,
            'status' => 'running',
            'failure' => null,
        ]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_missing_valid_uuid_and_inactive_tenant_context_are_fail_closed_no_ops(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        app(TenantContext::class)->activate($tenant);

        $this->assertFalse(
            app(ExternalExecutionFailureService::class)->failExternal((string) Str::uuid(), $owner->id, 'missing execution'),
        );

        app(TenantContext::class)->forget();
        $this->assertFalse(
            app(ExternalExecutionFailureService::class)->failExternal((string) Str::uuid(), $owner->id, 'no tenant context'),
        );
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_malformed_operation_id_and_invalid_owner_identity_are_rejected_before_lookup(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);

        try {
            app(ExternalExecutionFailureService::class)->failExternal('not-a-uuid', 1, 'bad id');
            $this->fail('Expected malformed execution UUID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('UUID', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        app(ExternalExecutionFailureService::class)->failExternal((string) Str::uuid(), 0, 'bad owner');
    }

    private function insertApproval(int $tenantId, int $actorUserId, string $status): int
    {
        return (int) DB::table('approvals')->insertGetId([
            'tenant_id' => $tenantId,
            'suggestion_id' => null,
            'actor_user_id' => $actorUserId,
            'status' => $status,
            'before_state' => json_encode(['title' => 'before'], JSON_THROW_ON_ERROR),
            'proposed_state' => json_encode(['title' => 'after'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertExecution(
        int $tenantId,
        int $actorUserId,
        int $approvalId,
        string $status,
        string $operationId,
    ): int {
        $siteId = (int) DB::table('sites')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Execution failure fixture '.$approvalId,
            'url' => 'https://execution-failure-'.$tenantId.'-'.$approvalId.'.example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('executions')->insertGetId([
            'operation_id' => $operationId,
            'request_id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'approval_id' => $approvalId,
            'actor_user_id' => $actorUserId,
            'status' => $status,
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
