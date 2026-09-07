<?php

namespace App\Models;

use App\Sync\SyncCancellationRequested;

class SyncBatch extends ContentDomainModel
{
    protected static function booted(): void
    {
        static::saving(function (SyncBatch $batch): void {
            if (! $batch->exists || $batch->state === 'cancelled' || ! $batch->sync_run_id) {
                return;
            }

            $runState = SyncRun::withoutGlobalScopes()
                ->whereKey($batch->sync_run_id)
                ->value('state');

            if ($runState === 'cancel_requested') {
                throw new SyncCancellationRequested((int) $batch->sync_run_id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'cursor' => 'array',
            'next_cursor' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function run()
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }
}
