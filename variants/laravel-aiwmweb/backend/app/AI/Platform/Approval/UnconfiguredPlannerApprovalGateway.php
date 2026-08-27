<?php

namespace App\AI\Platform\Approval;

use App\AI\Platform\Contracts\PlannerApprovalGateway;
use App\Models\AiPlannerItem;
use RuntimeException;

final class UnconfiguredPlannerApprovalGateway implements PlannerApprovalGateway
{
    public function submit(AiPlannerItem $item, int $actorUserId): string
    {
        throw new RuntimeException('Approval workflow integration is not configured for the AI content planner.');
    }
}
