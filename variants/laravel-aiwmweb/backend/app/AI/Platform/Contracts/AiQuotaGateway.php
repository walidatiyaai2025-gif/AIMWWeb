<?php

namespace App\AI\Platform\Contracts;

interface AiQuotaGateway
{
    /**
     * @return array{allowed:bool,code:string,message:string,limit:?int,current:?int}
     */
    public function check(int $tenantId, int $userId, string $workflow, int $requestedAdditional = 1): array;
}
