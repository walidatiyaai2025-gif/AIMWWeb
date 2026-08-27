<?php

namespace App\Sync\Contracts;

interface SyncSiteGuard
{
    public function assertAccessible(int $siteId): void;
}
