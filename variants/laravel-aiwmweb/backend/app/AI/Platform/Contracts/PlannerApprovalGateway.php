<?php

namespace App\AI\Platform\Contracts;

use App\Models\AiPlannerItem;

interface PlannerApprovalGateway
{
    public function submit(AiPlannerItem $item, int $actorUserId): string;
}
