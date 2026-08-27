<?php

namespace App\Jobs;

use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

final class RuntimeQueueSmokeJob extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(int $tenantId, public readonly string $token)
    {
        parent::__construct($tenantId);
        $this->onQueue('runtime');
    }

    public function backoff(): array
    {
        return [1, 5, 15];
    }

    public function handle(TenantCache $cache, TenantContext $context): void
    {
        $cache->put("runtime-smoke:{$this->token}", [
            'tenant_id' => $this->tenantId,
            'context_id' => $context->id(),
            'completed_at' => now()->toIso8601String(),
        ], 60);

        Log::info('queue.runtime_smoke.completed', [
            'job_id' => $this->job?->getJobId(),
            'tenant_id' => $this->tenantId,
        ]);
    }
}
