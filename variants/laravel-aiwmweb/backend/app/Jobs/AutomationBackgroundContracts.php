<?php

namespace App\Jobs;

/**
 * Canonical background contract bridge for the final Automation parity phase.
 *
 * This class does not introduce a second job runtime. It exposes the already
 * production-backed tenant-scoped JobCancellationRegistry and ExecutionJobStore
 * under the remaining canonical contract identities.
 */
final class AutomationBackgroundContracts
{
    public const JOB_CANCELLATION_REGISTRY_OPERATION_ID = 'AIMW-AUTO-AFDB35513B';
    public const EXECUTION_JOB_STORE_OPERATION_ID = 'AIMW-AUTO-50F3EC7087';
    public const EXECUTION_JOB_LIST_ITEM_OPERATION_ID = 'AIMW-AUTO-CA7398A8F6';

    public const CANONICAL_JOB_CANCELLATION_REGISTRY = 'IJobCancellationRegistry';
    public const CANONICAL_EXECUTION_JOB_STORE = 'IExecutionJobStore';
    public const CANONICAL_EXECUTION_JOB_LIST_ITEM = 'ExecutionJobListItem';

    public function __construct(
        private readonly JobCancellationRegistry $cancellationRegistry,
        private readonly ExecutionJobStore $executionJobStore,
    ) {}

    public function jobCancellationRegistry(): JobCancellationRegistry
    {
        return $this->cancellationRegistry;
    }

    public function executionJobStore(): ExecutionJobStore
    {
        return $this->executionJobStore;
    }

    /** @return array<string, mixed>|null */
    public function executionJobListItem(string $jobId): ?array
    {
        return $this->executionJobStore->get($jobId);
    }
}
