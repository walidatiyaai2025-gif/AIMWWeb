<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterCancelService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class ExecutionCenterCancelTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-DBF5562324';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_cancel_identity_is_bound_to_the_laravel_service(): void
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
        $this->assertSame('Cancel', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterCancelService::OPERATION_ID);
    }

    public function test_cancel_transitions_owned_tracked_queued_running_and_paused_jobs_and_records_warning_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        $service = app(ExecutionCenterCancelService::class);

        foreach (['queued', 'running', 'paused'] as $index => $status) {
            [$rowId, $jobId] = $this->execution(
                $tenant,
                $owner->user_id,
                $status,
                'Tracked',
                safeToCancel: true,
                progress: 17 + $index,
            );

            $this->assertTrue($service->cancel($jobId, $owner->user_id));

            $row = DB::table('operation_executions')->where('id', $rowId)->first();
            $this->assertNotNull($row);
            $this->assertSame('cancelled', $row->status);
            $this->assertSame(17 + $index, (int) $row->progress);
            $this->assertNotNull($row->completed_at);

            $logs = DB::table('operation_logs')
                ->where('operation_execution_id', $rowId)
                ->get();
            $this->assertCount(1, $logs);
            $this->assertSame('warning', $logs[0]->level);
            $this->assertSame(ExecutionCenterCancelService::CANCEL_MESSAGE, $logs[0]->message);
            $context = json_decode((string) $logs[0]->context, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(self::OPERATION_ID, $context['operation_id']);
            $this->assertSame('ExecutionCenterService.Cancel', $context['source']);
            $this->assertSame($owner->user_id, (int) $context['owner_user_id']);
        }
    }

    public function test_cancel_is_a_single_terminal_transition_and_does_not_duplicate_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$rowId, $jobId] = $this->execution($tenant, $owner->user_id, 'running', 'Tracked');
        $service = app(ExecutionCenterCancelService::class);

        $this->assertTrue($service->cancel($jobId, $owner->user_id));
        $this->assertFalse($service->cancel($jobId, $owner->user_id));
        $this->assertSame(1, DB::table('operation_logs')
            ->where('operation_execution_id', $rowId)
            ->where('message', ExecutionCenterCancelService::CANCEL_MESSAGE)
            ->count());
    }

    public function test_cancel_rejects_external_terminal_and_unsafe_jobs_without_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$externalId, $externalJob] = $this->execution($tenant, $owner->user_id, 'running', 'External');
        [$completedId, $completedJob] = $this->execution($tenant, $owner->user_id, 'completed', 'Tracked');
        [$unsafeId, $unsafeJob] = $this->execution($tenant, $owner->user_id, 'running', 'Tracked', safeToCancel: false);
        $service = app(ExecutionCenterCancelService::class);

        $this->assertFalse($service->cancel($externalJob, $owner->user_id));
        $this->assertFalse($service->cancel($completedJob, $owner->user_id));
        $this->assertFalse($service->cancel($unsafeJob, $owner->user_id));

        $this->assertSame('running', DB::table('operation_executions')->where('id', $externalId)->value('status'));
        $this->assertSame('completed', DB::table('operation_executions')->where('id', $completedId)->value('status'));
        $this->assertSame('running', DB::table('operation_executions')->where('id', $unsafeId)->value('status'));
        $this->assertSame(0, DB::table('operation_logs')->count());
    }

    public function test_cancel_fails_closed_for_wrong_owner_permission_and_foreign_tenant(): void
    {
        [$alpha, $alphaOwner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($alpha, $alphaOwner);
        [$alphaRowId, $alphaJobId] = $this->execution($alpha, $alphaOwner->user_id, 'running', 'Tracked');

        [, $otherOwner] = $this->tenantWithPermission('other-owner', true);
        app(TenantContext::class)->activate($alpha, $alphaOwner);
        try {
            app(ExecutionCenterCancelService::class)->cancel($alphaJobId, $otherOwner->user_id);
            $this->fail('A caller must not cancel another owner\'s execution.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame('running', DB::table('operation_executions')->where('id', $alphaRowId)->value('status'));

        app(TenantContext::class)->forget();
        [$beta, $betaOwner] = $this->tenantWithPermission('beta', true);
        app(TenantContext::class)->activate($beta, $betaOwner);
        $this->assertFalse(app(ExecutionCenterCancelService::class)->cancel($alphaJobId, $betaOwner->user_id));
        $this->assertDatabaseMissing('operation_executions', [
            'tenant_id' => $beta->id,
            'correlation_id' => $alphaJobId,
        ]);
        $this->assertDatabaseMissing('operation_logs', [
            'tenant_id' => $beta->id,
            'correlation_id' => $alphaJobId,
        ]);
        $this->assertSame('running', DB::table('operation_executions')->where('id', $alphaRowId)->value('status'));

        app(TenantContext::class)->forget();
        [$gamma, $unprivileged] = $this->tenantWithPermission('gamma', false);
        app(TenantContext::class)->activate($gamma, $unprivileged);
        [, $gammaJobId] = $this->execution($gamma, $unprivileged->user_id, 'running', 'Tracked');
        $this->expectException(AuthorizationException::class);
        app(ExecutionCenterCancelService::class)->cancel($gammaJobId, $unprivileged->user_id);
    }

    public function test_cancel_requires_valid_identity_and_active_tenant_context_before_writing(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$rowId, $jobId] = $this->execution($tenant, $owner->user_id, 'running', 'Tracked');
        $service = app(ExecutionCenterCancelService::class);

        foreach ([['not-a-uuid', $owner->user_id], [$jobId, 0]] as [$candidateJobId, $candidateOwner]) {
            try {
                $service->cancel($candidateJobId, $candidateOwner);
                $this->fail('Invalid cancellation identity must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('running', DB::table('operation_executions')->where('id', $rowId)->value('status'));
        $this->assertSame(0, DB::table('operation_logs')->count());

        app(TenantContext::class)->forget();
        try {
            $service->cancel($jobId, $owner->user_id);
            $this->fail('Cancel must fail closed without an active tenant context.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertSame('running', DB::table('operation_executions')->where('id', $rowId)->value('status'));
        $this->assertSame(0, DB::table('operation_logs')->count());
    }

    /** @return array{0:Tenant,1:TenantMembership} */
    private function tenantWithPermission(string $slug, bool $operationsManage): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role']);
        $permissions = ['tenant.view'];
        if ($operationsManage) {
            $permissions[] = 'operations.manage';
        }
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    /** @return array{0:int,1:string} */
    private function execution(
        Tenant $tenant,
        int $ownerUserId,
        string $status,
        string $mode,
        bool $safeToCancel = true,
        int $progress = 25,
    ): array {
        $sequence = DB::table('operation_executions')->count() + 1;
        $jobId = sprintf('00000000-0000-0000-0000-%012d', 246000 + $sequence);
        $now = now();
        $rowId = (int) DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $ownerUserId,
            'type' => 'bulk.content',
            'subject_type' => 'site',
            'subject_id' => (string) $sequence,
            'correlation_id' => $jobId,
            'status' => $status,
            'progress' => $progress,
            'attempts' => $status === 'queued' ? 0 : 1,
            'max_attempts' => 1,
            'safe_to_cancel' => $safeToCancel,
            'payload' => json_encode([
                'title' => 'Cancellation fixture',
                'site_name' => 'Fixture Site',
                'total_items' => 5,
                'processed_items' => 2,
                'execution_mode' => $mode,
            ], JSON_THROW_ON_ERROR),
            'result' => null,
            'failure' => null,
            'started_at' => in_array($status, ['running', 'paused'], true) ? $now : null,
            'completed_at' => $status === 'completed' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$rowId, $jobId];
    }
}
