<?php

namespace App\Billing;

use App\Models\BillingAudit;
use App\Tenancy\TenantContext;

final class BillingAuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    public function record(string $action, array $metadata = [], ?string $subjectType = null, int|string|null $subjectId = null, ?int $actorUserId = null, bool $system = false): BillingAudit
    {
        return BillingAudit::query()->create(['actor_user_id' => $system ? null : ($actorUserId ?? auth()->id()), 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'metadata' => $metadata, 'occurred_at' => now()]);
    }
}
