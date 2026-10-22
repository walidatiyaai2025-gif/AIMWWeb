<?php

namespace App\Operations;

interface ConnectorBackupGateway
{
    /** @return array<string, mixed> */
    public function startBackup(int $tenantId, ?string $siteKey, string $level, array $manifest, string $correlationId): array;

    /** @return array<string, mixed> */
    public function startRestore(int $tenantId, int $backupId, string $correlationId): array;
}
