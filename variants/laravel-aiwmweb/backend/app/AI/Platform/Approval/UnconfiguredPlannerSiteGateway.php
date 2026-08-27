<?php

namespace App\AI\Platform\Approval;

use App\AI\Platform\Contracts\PlannerSiteGateway;
use RuntimeException;

final class UnconfiguredPlannerSiteGateway implements PlannerSiteGateway
{
    public function assertOwned(?int $siteId): void
    {
        if ($siteId !== null) {
            throw new RuntimeException('Site ownership integration is not configured for the AI content planner.');
        }
    }
}
