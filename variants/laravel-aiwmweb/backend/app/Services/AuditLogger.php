<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Tenancy\TenantContext;

final class AuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    public function record(string $event, array $metadata = [], ?string $subjectType = null, int|string|null $subjectId = null): AuditEvent
    {
        return AuditEvent::query()->create([
            'actor_user_id' => $this->context->membership()->user_id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
