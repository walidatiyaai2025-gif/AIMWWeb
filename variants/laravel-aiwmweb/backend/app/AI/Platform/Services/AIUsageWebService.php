<?php

namespace App\AI\Platform\Services;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;

/** Tenant-scoped read boundary matching the canonical AIUsageWebService surface. */
final class AIUsageWebService
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function getAsync(?int $siteId = null, int $take = 1000): array
    {
        $this->authorizer->authorize('tenant.view');
        $filters = ['take' => min(max($take, 1), 1000)];
        if ($siteId !== null) {
            Site::query()->findOrFail($siteId);
            $filters['site_id'] = $siteId;
        }

        return $this->usage->report($filters);
    }

    public function getRecentAsync(int $take = 100, ?int $siteId = null): array
    {
        $report = $this->getAsync($siteId, $take);

        return array_slice($report['recent'] ?? [], 0, min(max($take, 1), 1000));
    }
}
