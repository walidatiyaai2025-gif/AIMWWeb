<?php

namespace App\Models;

use App\Sync\SyncCancellationRequested;

class SyncItem extends ContentDomainModel
{
    protected static function booted(): void
    {
        static::saving(function (SyncItem $item): void {
            if (! $item->sync_run_id) {
                return;
            }

            $runState = SyncRun::withoutGlobalScopes()
                ->whereKey($item->sync_run_id)
                ->value('state');

            if ($runState === 'cancel_requested') {
                throw new SyncCancellationRequested((int) $item->sync_run_id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'remote_payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
