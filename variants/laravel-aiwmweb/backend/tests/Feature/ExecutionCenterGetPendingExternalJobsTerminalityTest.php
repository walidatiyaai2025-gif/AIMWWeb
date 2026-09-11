<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterPendingExternalJobsService;
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

final class ExecutionCenterGetPendingExternalJobsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-E587C12B37';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_get_pending_external_jobs_identity_is_bound_to_the_laravel_service(): void
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
        $this->assertSame('GetPendingExternalJobs', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterPendingExternalJobsService::OPERATION_ID);
    }

    public function test_get_pending_external_jobs_returns_only_active_tenant_external_waiting_rows_oldest_first_without_writes(): void
    {
        [$alpha, $alphaMembership] = $this->tenantWithPermission('alpha', true);
        [$beta, $betaMembership] = $this->tenantWithPermission('beta', true);
        $base = now()->subMinutes(10);

        $betaJobId = $this->execution($beta, $betaMembership->user_id, 'queued', 'External', $base);
        $oldest = $this->execution(
            $alpha,
            $alphaMembership->user_id,
            'queued',
            'External',
            $base->copy()->addSecond(),
            title: 'Import token=alpha-secret',
            idempotencyDisplay: 'api_key=alpha-key',
            failure: 'password=alpha-password',
        );
        $this->execution($alpha, $alphaMembership->user_id, 'queued', 'Tracked', $base->copy()->addSeconds(2));
        $this->execution($alpha, $alphaMembership->user_id, 'running', 'External', $base->copy()->addSeconds(3));
        $this->execution($alpha, null, 'queued', 'External', $base->copy()->addSeconds(4));
        $this->execution($alpha, $alphaMembership->user_id, 'queued', 'External', $base->copy()->addSeconds(5), subjectId: null);
        $newest = $this->execution($alpha, $alphaMembership->user_id, 'queued', 'External', $base->copy()->addSeconds(6));

        app(TenantContext::class)->activate($alpha, $alphaMembership);
        $executionCount = DB::table('operation_executions')->count();
        $logCount = DB::table('operation_logs')->count();

        $jobs = app(ExecutionCenterPendingExternalJobsService::class)->getPendingExternalJobs();

        $this->assertSame([$oldest, $newest], array_column($jobs, 'job_id'));
        $this->assertNotContains($betaJobId, array_column($jobs, 'job_id'));
        $this->assertSame(['queued', 'queued'], array_column($jobs, 'status'));
        $this->assertSame(['External', 'External'], array_column($jobs, 'execution_mode'));
        $this->assertSame([$alphaMembership->user_id, $alphaMembership->user_id], array_column($jobs, 'owner_user_id'));
        $this->assertSame($executionCount, DB::table('operation_executions')->count());
        $this->assertSame($logCount, DB::table('operation_logs')->count());

        $serialized = json_encode($jobs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('alpha-secret', $serialized);
        $this->assertStringNotContainsString('alpha-key', $serialized);
        $this->assertStringNotContainsString('alpha-password', $serialized);
        $this->assertStringContainsString('[REDACTED]', $serialized);
    }

    public function test_get_pending_external_jobs_clamps_take_between_one_and_one_hundred(): void
    {
        [$tenant, $membership] = $this->tenantWithPermission('alpha', true);
        $base = now()->subHour();
        $jobIds = [];

        for ($index = 0; $index < 101; $index++) {
            $jobIds[] = $this->execution(
                $tenant,
                $membership->user_id,
                'queued',
                'External',
                $base->copy()->addSeconds($index),
            );
        }

        app(TenantContext::class)->activate($tenant, $membership);
        $service = app(ExecutionCenterPendingExternalJobsService::class);

        $minimum = $service->getPendingExternalJobs(0);
        $maximum = $service->getPendingExternalJobs(999);

        $this->assertCount(1, $minimum);
        $this->assertSame($jobIds[0], $minimum[0]['job_id']);
        $this->assertCount(100, $maximum);
        $this->assertSame($jobIds[0], $maximum[0]['job_id']);
        $this->assertSame($jobIds[99], $maximum[99]['job_id']);
        $this->assertNotContains($jobIds[100], array_column($maximum, 'job_id'));
    }

    public function test_get_pending_external_jobs_fails_closed_without_context_or_operations_manage_permission(): void
    {
        [$tenant, $membership] = $this->tenantWithPermission('alpha', false);
        $jobId = $this->execution($tenant, $membership->user_id, 'queued', 'External', now());
        $service = app(ExecutionCenterPendingExternalJobsService::class);

        try {
            $service->getPendingExternalJobs();
            $this->fail('GetPendingExternalJobs must fail closed without an active tenant context.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->activate($tenant, $membership);
        try {
            $service->getPendingExternalJobs();
            $this->fail('GetPendingExternalJobs must require operations.manage.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('operation_executions', [
            'tenant_id' => $tenant->id,
            'correlation_id' => $jobId,
            'status' => 'queued',
        ]);
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
        ?int $ownerUserId,
        string $status,
        string $mode,
        mixed $createdAt,
        ?string $title = null,
        ?string $idempotencyDisplay = null,
        ?string $failure = null,
        ?int $subjectId = 1,
    ): string {
        $sequence = DB::table('operation_executions')->count() + 1;
        $jobId = sprintf('00000000-0000-0000-0000-%012d', 245000 + $sequence);

        DB::table('operation_executions')->insert([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $ownerUserId,
            'type' => 'sync.external',
            'subject_type' => 'site',
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'correlation_id' => $jobId,
            'status' => $status,
            'progress' => 0,
            'attempts' => 0,
            'max_attempts' => 1,
            'safe_to_cancel' => true,
            'payload' => json_encode([
                'title' => $title ?? 'External pending fixture',
                'site_name' => 'Fixture Site',
                'total_items' => 5,
                'processed_items' => 0,
                'execution_mode' => $mode,
                'idempotency_display' => $idempotencyDisplay ?? 'fixture-key',
            ], JSON_THROW_ON_ERROR),
            'result' => null,
            'failure' => $failure,
            'started_at' => $status === 'running' ? $createdAt : null,
            'completed_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $jobId;
    }
}
