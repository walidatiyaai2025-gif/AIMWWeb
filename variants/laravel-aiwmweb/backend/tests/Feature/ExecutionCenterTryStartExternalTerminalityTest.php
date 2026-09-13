<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterExternalStartService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class ExecutionCenterTryStartExternalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-8836C7A28A';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_try_start_external_identity_is_bound_to_the_laravel_service(): void
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
        $this->assertSame('TryStartExternal', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterExternalStartService::OPERATION_ID);
    }

    public function test_try_start_external_transitions_one_owned_external_job_and_records_one_info_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$rowId, $jobId] = $this->execution(
            $tenant,
            $owner->user_id,
            'queued',
            'External',
            progress: 17,
            processedItems: 2,
            failure: 'stale failure',
            completedAt: now()->subMinute(),
        );

        $started = app(ExecutionCenterExternalStartService::class)->tryStartExternal($jobId, $owner->user_id);

        $this->assertTrue($started);
        $row = DB::table('operation_executions')->where('id', $rowId)->first();
        $this->assertNotNull($row);
        $this->assertSame('running', $row->status);
        $this->assertSame(17, (int) $row->progress);
        $this->assertNotNull($row->started_at);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->failure);
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('External', $payload['execution_mode']);
        $this->assertSame(5, $payload['total_items']);
        $this->assertSame(2, $payload['processed_items']);

        $logs = DB::table('operation_logs')->where('operation_execution_id', $rowId)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('info', $logs[0]->level);
        $this->assertSame(ExecutionCenterExternalStartService::START_MESSAGE, $logs[0]->message);
        $context = json_decode((string) $logs[0]->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('ExecutionCenterService.TryStartExternal', $context['source']);
        $this->assertSame($owner->user_id, (int) $context['owner_user_id']);
    }

    public function test_try_start_external_is_single_transition_and_never_duplicates_start_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$rowId, $jobId] = $this->execution($tenant, $owner->user_id, 'queued', 'External');
        $service = app(ExecutionCenterExternalStartService::class);

        $this->assertTrue($service->tryStartExternal($jobId, $owner->user_id));
        $this->assertFalse($service->tryStartExternal($jobId, $owner->user_id));
        $this->assertSame(1, DB::table('operation_logs')
            ->where('operation_execution_id', $rowId)
            ->where('level', 'info')
            ->where('message', ExecutionCenterExternalStartService::START_MESSAGE)
            ->count());
    }

    public function test_try_start_external_safely_ignores_missing_non_queued_and_non_external_jobs(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$runningRowId, $runningJobId] = $this->execution($tenant, $owner->user_id, 'running', 'External');
        [$trackedRowId, $trackedJobId] = $this->execution($tenant, $owner->user_id, 'queued', 'Tracked');
        $service = app(ExecutionCenterExternalStartService::class);

        $this->assertFalse($service->tryStartExternal('00000000-0000-0000-0000-999999999999', $owner->user_id));
        $this->assertFalse($service->tryStartExternal($runningJobId, $owner->user_id));
        $this->assertFalse($service->tryStartExternal($trackedJobId, $owner->user_id));

        $this->assertSame('running', DB::table('operation_executions')->where('id', $runningRowId)->value('status'));
        $this->assertSame('queued', DB::table('operation_executions')->where('id', $trackedRowId)->value('status'));
        $this->assertSame(0, DB::table('operation_logs')->count());
    }

    public function test_try_start_external_fails_closed_for_wrong_owner_permission_and_foreign_tenant(): void
    {
        [$alpha, $alphaOwner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($alpha, $alphaOwner);
        [$alphaRowId, $alphaJobId] = $this->execution($alpha, $alphaOwner->user_id, 'queued', 'External');

        [, $otherOwner] = $this->tenantWithPermission('other-owner', true);
        app(TenantContext::class)->activate($alpha, $alphaOwner);
        try {
            app(ExecutionCenterExternalStartService::class)->tryStartExternal($alphaJobId, $otherOwner->user_id);
            $this->fail('A caller must not start another owner\'s execution.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->forget();
        [$beta, $betaOwner] = $this->tenantWithPermission('beta', true);
        app(TenantContext::class)->activate($beta, $betaOwner);
        $this->assertFalse(app(ExecutionCenterExternalStartService::class)->tryStartExternal(
            $alphaJobId,
            $betaOwner->user_id,
        ));
        $this->assertDatabaseMissing('operation_executions', [
            'tenant_id' => $beta->id,
            'correlation_id' => $alphaJobId,
        ]);
        $this->assertDatabaseMissing('operation_logs', [
            'tenant_id' => $beta->id,
            'correlation_id' => $alphaJobId,
        ]);
        $this->assertSame('queued', DB::table('operation_executions')->where('id', $alphaRowId)->value('status'));

        app(TenantContext::class)->forget();
        [$gamma, $unprivileged] = $this->tenantWithPermission('gamma', false);
        app(TenantContext::class)->activate($gamma, $unprivileged);
        [, $gammaJobId] = $this->execution($gamma, $unprivileged->user_id, 'queued', 'External');
        $this->expectException(AuthorizationException::class);
        app(ExecutionCenterExternalStartService::class)->tryStartExternal($gammaJobId, $unprivileged->user_id);
    }

    public function test_try_start_external_requires_valid_identity_and_active_tenant_context_before_writing(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        [$rowId, $jobId] = $this->execution($tenant, $owner->user_id, 'queued', 'External');
        $service = app(ExecutionCenterExternalStartService::class);

        foreach ([['not-a-uuid', $owner->user_id], [$jobId, 0]] as [$candidateJobId, $candidateOwner]) {
            try {
                $service->tryStartExternal($candidateJobId, $candidateOwner);
                $this->fail('Invalid TryStartExternal identity must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('queued', DB::table('operation_executions')->where('id', $rowId)->value('status'));
        $this->assertSame(0, DB::table('operation_logs')->count());

        app(TenantContext::class)->forget();
        try {
            $service->tryStartExternal($jobId, $owner->user_id);
            $this->fail('TryStartExternal must fail closed without an active tenant context.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertSame('queued', DB::table('operation_executions')->where('id', $rowId)->value('status'));
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
        int $progress = 0,
        int $processedItems = 0,
        ?string $failure = null,
        mixed $completedAt = null,
    ): array {
        $sequence = DB::table('operation_executions')->count() + 1;
        $jobId = sprintf('00000000-0000-0000-0000-%012d', 244000 + $sequence);
        $now = now();
        $rowId = (int) DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $ownerUserId,
            'type' => 'sync.external',
            'subject_type' => 'site',
            'subject_id' => (string) $sequence,
            'correlation_id' => $jobId,
            'status' => $status,
            'progress' => $progress,
            'attempts' => 0,
            'max_attempts' => 1,
            'safe_to_cancel' => true,
            'payload' => json_encode([
                'title' => 'External start fixture',
                'site_name' => 'Fixture Site',
                'total_items' => 5,
                'processed_items' => $processedItems,
                'execution_mode' => $mode,
            ], JSON_THROW_ON_ERROR),
            'result' => null,
            'failure' => $failure,
            'started_at' => $status === 'running' ? $now : null,
            'completed_at' => $completedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$rowId, $jobId];
    }
}
