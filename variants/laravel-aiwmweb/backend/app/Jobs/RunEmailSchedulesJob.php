<?php

namespace App\Jobs;

use App\Email\Services\EmailScheduleService;

final class RunEmailSchedulesJob extends TenantAwareJob
{
    public int $tries = 2;
    public int $timeout = 120;

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:email-schedules";
    }

    public function handle(EmailScheduleService $service): void
    {
        $service->dispatchDue();
    }
}
