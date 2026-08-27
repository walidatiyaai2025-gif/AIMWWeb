<?php

namespace App\Jobs;

use App\Models\SeoAudit;
use App\Services\SeoManagerService;
use Throwable;

final class RunSeoAuditJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $auditId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:audit:{$this->auditId}";
    }

    public function handle(SeoManagerService $seo): void
    {
        $audit = SeoAudit::query()->findOrFail($this->auditId);
        try {
            $seo->runAudit($audit);
        } catch (Throwable $e) {
            $audit->update([
                'status' => 'failed',
                'failure' => $e->getMessage(),
                'current_item' => null,
                'completed_at' => now(),
            ]);
            throw $e;
        }
    }
}
