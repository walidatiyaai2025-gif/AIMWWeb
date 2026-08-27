<?php

namespace App\Jobs;

use App\Sync\SyncRuntimeService;
use Throwable;

final class ProcessSyncBatchJob extends TenantAwareJob
{
    public int $tries = 4;

    public array $backoff = [30, 120, 300];

    public function __construct(int $tenantId, public readonly int $syncBatchId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:sync-batch:{$this->syncBatchId}";
    }

    public function handle(SyncRuntimeService $runtime): void
    {
        try {
            $runtime->processBatch($this->tenantId, $this->syncBatchId);
        } catch (Throwable $exception) {
            $runtime->recordBatchFailure($this->syncBatchId, $exception, $this->attempts() >= $this->tries);
            throw $exception;
        }
    }
}
