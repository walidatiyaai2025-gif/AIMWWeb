<?php

namespace App\Jobs;

use App\Sync\SyncRuntimeService;

final class RetrySyncItemJob extends TenantAwareJob
{
    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(int $tenantId, public readonly int $syncItemId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:sync-item:{$this->syncItemId}";
    }

    public function handle(SyncRuntimeService $runtime): void
    {
        $runtime->processRetryItem($this->syncItemId);
    }
}
