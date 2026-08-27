<?php

namespace App\Jobs;

use App\Email\Services\EmailDeliveryService;

final class SendEmailDeliveryJob extends TenantAwareJob
{
    public int $tries = 5;
    public int $timeout = 60;

    public function __construct(int $tenantId, public readonly int $deliveryId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:email-delivery:{$this->deliveryId}";
    }

    public function handle(EmailDeliveryService $service): void
    {
        $result = $service->send($this->deliveryId);
        if ($result['retry']) {
            $this->release($result['delay']);
        }
    }
}
