<?php

namespace App\Jobs;

use App\Sync\SyncCancellationRequested;
use App\Sync\SyncCancellationService;
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

    public function handle(SyncRuntimeService $runtime, SyncCancellationService $cancellation): void
    {
        if ($cancellation->finalizeBatchIfRequested($this->syncBatchId)) {
            return;
        }

        try {
            $runtime->processBatch($this->tenantId, $this->syncBatchId);
        } catch (SyncCancellationRequested) {
            $cancellation->finalizeBatch($this->syncBatchId);
        } catch (Throwable $exception) {
            if ($cancellation->finalizeBatchIfRequested($this->syncBatchId)) {
                return;
            }

            $runtime->recordBatchFailure($this->syncBatchId, $exception, $this->attempts() >= $this->tries);
            throw $exception;
        }
    }
}
