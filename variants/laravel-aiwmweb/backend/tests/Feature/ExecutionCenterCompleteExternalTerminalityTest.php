<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterExternalCompletionService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ExecutionCenterCompleteExternalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-0701C84252';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_complete_external_identity_is_bound_to_the_laravel_service(): void
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
        $this->assertSame('CompleteExternal', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterExternalCompletionService::OPERATION_ID);
    }

    public function test_complete_external_transitions_running_external_job_and_records_one_success_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        $executionId = $this->execution($tenant, $owner->user_id, 'running', 'External', 7, 3, 'old failure');

        $completed = app(ExecutionCenterExternalCompletionService::class)->completeExternal(
            $executionId,
            $owner->user_id,
            'token=super-secret finished successfully',
        );

        $this->assertNotNull($completed);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(100, $completed['progress']);
        $this->assertSame(7, $completed['total_items']);
        $this->assertSame(7, $completed['processed_items']);
        $this->assertSame('External', $completed['execution_mode']);
        $this->assertNotNull($completed['completed_at']);
        $this->assertNull($completed['error']);

        $row = DB::table('operation_executions')->where('id', $executionId)->first();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertSame(100, (int) $row->progress);
        $this->assertNull($row->failure);
        $this->assertNotNull($row->completed_at);
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(7, $payload['total_items']);
        $this->assertSame(7, $payload['processed_items']);

        $logs = DB::table('operation_logs')->where('operation_execution_id', $executionId)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('success', $logs[0]->level);
        $this->assertStringContainsString('token=[REDACTED]', (string) $logs[0]->message);
        $this->assertStringNotContainsString('super-secret', (string) $logs[0]->message);
        $context = json_decode((string) $logs[0]->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('ExecutionCenterService.CompleteExternal', $context['source']);
    }

    public function test_complete_external_is_single_transition_and_does_not_duplicate_success_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        $executionId = $this->execution($tenant, $owner->user_id, 'running', 'External', 2, 1);
        $service = app(ExecutionCenterExternalCompletionService::class);

        $this->assertNotNull($service->completeExternal($executionId, $owner->user_id, 'first completion'));
        $this->assertNull($service->completeExternal($executionId, $owner->user_id, 'duplicate completion'));
        $this->assertSame(1, DB::table('operation_logs')
            ->where('operation_execution_id', $executionId)
            ->where('level', 'success')
            ->count());
    }

    public function test_complete_external_safely_ignores_missing_non_running_and_non_external_jobs(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        $queuedId = $this->execution($tenant, $owner->user_id, 'queued', 'External', 4, 0);
        $trackedId = $this->execution($tenant, $owner->user_id, 'running', 'Tracked', 4, 2);
        $service = app(ExecutionCenterExternalCompletionService::class);

        $this->assertNull($service->completeExternal(999999, $owner->user_id, 'missing'));
        $this->assertNull($service->completeExternal($queuedId, $owner->user_id, 'not running'));
        $this->assertNull($service->completeExternal($trackedId, $owner->user_id, 'not external'));

        $this->assertSame('queued', DB::table('operation_executions')->where('id', $queuedId)->value('status'));
        $this->assertSame('running', DB::table('operation_executions')->where('id', $trackedId)->value('status'));
        $this->assertSame(0, DB::table('operation_logs')->count());
    }

    public function test_complete_external_fails_closed_for_wrong_owner_permission_and_foreign_tenant(): void
    {
        [$alpha, $alphaOwner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($alpha, $alphaOwner);
        $alphaExecutionId = $this->execution($alpha, $alphaOwner->user_id, 'running', 'External', 3, 1);

        [, $otherOwner] = $this->tenantWithPermission('other-owner', true);
        app(TenantContext::class)->activate($alpha, $alphaOwner);
        try {
            app(ExecutionCenterExternalCompletionService::class)->completeExternal(
                $alphaExecutionId,
                $otherOwner->user_id,
                'wrong owner',
            );
            $this->fail('A caller must not complete another owner\'s execution.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->forget();
        [$beta, $betaOwner] = $this->tenantWithPermission('beta', true);
        app(TenantContext::class)->activate($beta, $betaOwner);
        $this->assertNull(app(ExecutionCenterExternalCompletionService::class)->completeExternal(
            $alphaExecutionId,
            $betaOwner->user_id,
            'foreign tenant execution',
        ));

        app(TenantContext::class)->forget();
        [$gamma, $unprivileged] = $this->tenantWithPermission('gamma', false);
        app(TenantContext::class)->activate($gamma, $unprivileged);
        $gammaExecutionId = $this->execution($gamma, $unprivileged->user_id, 'running', 'External', 1, 0);
        $this->expectException(AuthorizationException::class);
        app(ExecutionCenterExternalCompletionService::class)->completeExternal(
            $gammaExecutionId,
            $unprivileged->user_id,
            'denied',
        );
    }

    public function test_complete_external_requires_active_tenant_context_before_any_write(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        app(TenantContext::class)->activate($tenant, $owner);
        $executionId = $this->execution($tenant, $owner->user_id, 'running', 'External', 1, 0);
        app(TenantContext::class)->forget();

        try {
            app(ExecutionCenterExternalCompletionService::class)->completeExternal(
                $executionId,
                $owner->user_id,
                'no tenant context',
            );
            $this->fail('CompleteExternal must fail closed without an active tenant context.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertSame('running', DB::table('operation_executions')->where('id', $executionId)->value('status'));
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

    private function execution(
        Tenant $tenant,
        int $ownerUserId,
        string $status,
        string $mode,
        int $totalItems,
        int $processedItems,
        ?string $failure = null,
    ): int {
        $sequence = DB::table('operation_executions')->count() + 1;
        $now = now();

        return (int) DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $ownerUserId,
            'type' => 'sync.external',
            'subject_type' => 'site',
            'subject_id' => (string) $sequence,
            'correlation_id' => sprintf('00000000-0000-0000-0000-%012d', 242000 + $sequence),
            'status' => $status,
            'progress' => $status === 'running' ? 50 : 0,
            'attempts' => $status === 'running' ? 1 : 0,
            'max_attempts' => 1,
            'safe_to_cancel' => true,
            'payload' => json_encode([
                'title' => 'External completion fixture',
                'site_name' => 'Fixture Site',
                'total_items' => $totalItems,
                'processed_items' => $processedItems,
                'execution_mode' => $mode,
            ], JSON_THROW_ON_ERROR),
            'result' => null,
            'failure' => $failure,
            'started_at' => $status === 'running' ? $now : null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
