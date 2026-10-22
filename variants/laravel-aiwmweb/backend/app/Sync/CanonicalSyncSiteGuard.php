<?php

namespace App\Sync;

use App\Sync\Contracts\SyncSiteGuard;
use RuntimeException;

final class CanonicalSyncSiteGuard implements SyncSiteGuard
{
    public function assertAccessible(int $siteId): void
    {
        $class = 'App\\Models\\Site';
        if (! class_exists($class)) {
            throw new RuntimeException('Canonical Site runtime is not integrated; sync fails closed.');
        }

        if (! $class::query()->whereKey($siteId)->exists()) {
            abort(404, 'Site not found.');
        }
    }
}
