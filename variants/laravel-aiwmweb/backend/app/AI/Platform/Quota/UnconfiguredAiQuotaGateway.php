<?php

namespace App\AI\Platform\Quota;

use App\AI\Platform\Contracts\AiQuotaGateway;

final class UnconfiguredAiQuotaGateway implements AiQuotaGateway
{
    public function check(int $tenantId, int $userId, string $workflow, int $requestedAdditional = 1): array
    {
        return [
            'allowed' => false,
            'code' => 'quota_backend_unavailable',
            'message' => 'Billing quota integration is not configured for the AI platform.',
            'limit' => null,
            'current' => null,
        ];
    }
}
