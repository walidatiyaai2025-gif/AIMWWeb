<?php

namespace App\AI\Platform\Contracts;

interface PlannerSiteGateway
{
    public function assertOwned(?int $siteId): void;
}
