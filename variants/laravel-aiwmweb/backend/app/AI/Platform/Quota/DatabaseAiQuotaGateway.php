<?php

namespace App\AI\Platform\Quota;

use App\AI\Platform\Contracts\AiQuotaGateway;
use App\Billing\EntitlementService;
use App\Models\AiGenerationRecord;
use App\Tenancy\TenantContext;

final class DatabaseAiQuotaGateway implements AiQuotaGateway
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
    ) {}

    public function check(int $tenantId, int $userId, string $workflow, int $requestedAdditional = 1): array
    {
        if ($tenantId !== $this->context->id() || $userId !== (int) $this->context->membership()->user_id) {
            return $this->unavailable('AI quota context does not match the active tenant membership.');
        }

        $requestedAdditional = max(1, $requestedAdditional);
        $limit = $this->resolveLimit();
        if ($limit === null) {
            return [
                'allowed' => true,
                'code' => 'unmetered',
                'message' => 'No application-level AI generation quota is configured.',
                'limit' => null,
                'current' => null,
            ];
        }

        if ($limit < 0) {
            return $this->unavailable('AI generation quota configuration is invalid.');
        }

        $current = AiGenerationRecord::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        $allowed = $current + $requestedAdditional <= $limit;

        return [
            'allowed' => $allowed,
            'code' => $allowed ? 'within_quota' : 'quota_exceeded',
            'message' => $allowed
                ? 'AI generation quota allows this request.'
                : 'Daily AI generation quota has been reached.',
            'limit' => $limit,
            'current' => $current,
        ];
    }

    private function resolveLimit(): ?int
    {
        $planLimit = $this->entitlements->limit('ai_generations_daily');
        if ($planLimit !== null) {
            return $planLimit;
        }

        $configured = config('ai.daily_generation_limit');
        if ($configured === null || $configured === '') {
            return null;
        }

        if (! is_numeric($configured)) {
            return -1;
        }

        return (int) $configured;
    }

    /** @return array{allowed:bool,code:string,message:string,limit:?int,current:?int} */
    private function unavailable(string $message): array
    {
        return [
            'allowed' => false,
            'code' => 'quota_backend_unavailable',
            'message' => $message,
            'limit' => null,
            'current' => null,
        ];
    }
}
