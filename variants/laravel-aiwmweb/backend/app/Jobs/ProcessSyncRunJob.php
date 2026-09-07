<?php

namespace App\Jobs;

use App\Sync\SyncCancellationRequested;
use App\Sync\SyncCancellationService;
use App\Sync\SyncRuntimeService;
use Throwable;

final class ProcessSyncRunJob extends TenantAwareJob
{
    public int $tries = 3;

    public array $backoff = [15, 60, 180];

    public function __construct(int $tenantId, public readonly int $syncRunId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:sync-run:{$this->syncRunId}";
    }

    public function handle(SyncRuntimeService $runtime, SyncCancellationService $cancellation): void
    {
        if ($cancellation->finalizeIfRequested($this->syncRunId)) {
            return;
        }

        try {
            $runtime->processRun($this->tenantId, $this->syncRunId);
        } catch (SyncCancellationRequested) {
            $cancellation->finalizeRun($this->syncRunId);
        } catch (Throwable $exception) {
            if ($cancellation->finalizeIfRequested($this->syncRunId)) {
                return;
            }

            $runtime->recordRunFailure($this->syncRunId, $exception, $this->attempts() >= $this->tries);
            throw $exception;
        }
    }
}
