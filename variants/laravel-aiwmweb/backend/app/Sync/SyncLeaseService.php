<?php

namespace App\Sync;

use App\Models\SyncSiteLease;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class SyncLeaseService
{
    public function acquire(int $siteId, string $ownerToken, string $purpose, int $ttlSeconds = 1800): bool
    {
        try {
            return DB::transaction(function () use ($siteId, $ownerToken, $purpose, $ttlSeconds): bool {
                $lease = SyncSiteLease::query()->where('site_id', $siteId)->lockForUpdate()->first();

                if ($lease && $lease->leased_until->isFuture() && $lease->owner_token !== $ownerToken) {
                    return false;
                }

                $lease ??= new SyncSiteLease(['site_id' => $siteId]);
                $lease->fill([
                    'owner_token' => $ownerToken,
                    'purpose' => $purpose,
                    'leased_until' => now()->addSeconds($ttlSeconds),
                ])->save();

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function refresh(int $siteId, string $ownerToken, int $ttlSeconds = 1800): bool
    {
        $lease = SyncSiteLease::query()
            ->where('site_id', $siteId)
            ->where('owner_token', $ownerToken);
        $updated = (clone $lease)->update(['leased_until' => now()->addSeconds($ttlSeconds), 'updated_at' => now()]);

        // MySQL reports zero affected rows when a refresh lands in the same
        // timestamp second even though this worker still owns the lease.
        return $updated === 1 || $lease->exists();
    }

    public function release(int $siteId, string $ownerToken): void
    {
        SyncSiteLease::query()
            ->where('site_id', $siteId)
            ->where('owner_token', $ownerToken)
            ->delete();
    }
}
