<?php

namespace App\Operations;

interface SyncOperationsGateway
{
    /** @return array<string, mixed> */
    public function retry(int $tenantId, array $operation): array;
}
