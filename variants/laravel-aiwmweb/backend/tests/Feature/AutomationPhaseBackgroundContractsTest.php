<?php

namespace Tests\Feature;

use App\Jobs\AutomationBackgroundContracts;
use App\Jobs\ExecutionJobStore;
use App\Jobs\JobCancellationRegistry;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomationPhaseBackgroundContractsTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_IDS = [
        'AIMW-AUTO-AFDB35513B',
        'AIMW-AUTO-50F3EC7087',
        'AIMW-AUTO-CA7398A8F6',
    ];

    public function test_job_cancellation_registry_is_tenant_scoped_and_stale_safe(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'background-a']);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'background-b']);
        $context = app(TenantContext::class);
        $jobId = (string) Str::uuid();
        $registrationA = (string) Str::uuid();
        $registrationB = (string) Str::uuid();

        $context->activate($tenantA);
        $registry = app(JobCancellationRegistry::class);
        $registry->register($jobId, $registrationA);
        $this->assertTrue($registry->isRegistered($jobId));
        $this->assertFalse($registry->isCancellationRequested($jobId, $registrationA));

        $context->activate($tenantB);
        $this->assertFalse($registry->isRegistered($jobId));
        $registry->register($jobId, $registrationB);
        $this->assertTrue($registry->tryCancel($jobId));
        $this->assertTrue($registry->isCancellationRequested($jobId, $registrationB));

        $context->activate($tenantA);
        $this->assertTrue($registry->isRegistered($jobId));
        $this->assertFalse($registry->isCancellationRequested($jobId, $registrationA));
        $this->assertFalse($registry->unregister($jobId, $registrationB));
        $this->assertTrue($registry->unregister($jobId, $registrationA));
    }

    public function test_existing_execution_store_is_the_canonical_i_execution_job_store_runtime(): void
    {
        $bridge = app(AutomationBackgroundContracts::class);

        $this->assertInstanceOf(JobCancellationRegistry::class, $bridge->jobCancellationRegistry());
        $this->assertInstanceOf(ExecutionJobStore::class, $bridge->executionJobStore());
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'start'));
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'report'));
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'complete'));
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'fail'));
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'cancel'));
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'getRecent'));
        $this->assertTrue(method_exists(ExecutionJobStore::class, 'get'));
        $this->assertSame('IJobCancellationRegistry', AutomationBackgroundContracts::CANONICAL_JOB_CANCELLATION_REGISTRY);
        $this->assertSame('IExecutionJobStore', AutomationBackgroundContracts::CANONICAL_EXECUTION_JOB_STORE);
        $this->assertSame('ExecutionJobListItem', AutomationBackgroundContracts::CANONICAL_EXECUTION_JOB_LIST_ITEM);
    }

    public function test_background_operation_ids_are_exact(): void
    {
        $this->assertSame(self::OPERATION_IDS[0], AutomationBackgroundContracts::JOB_CANCELLATION_REGISTRY_OPERATION_ID);
        $this->assertSame(self::OPERATION_IDS[1], AutomationBackgroundContracts::EXECUTION_JOB_STORE_OPERATION_ID);
        $this->assertSame(self::OPERATION_IDS[2], AutomationBackgroundContracts::EXECUTION_JOB_LIST_ITEM_OPERATION_ID);
    }
}
