<?php

namespace App\Models;

use App\Sync\SyncCancellationRequested;

class SyncRun extends DomainModel
{
    protected static function booted(): void
    {
        static::saving(function (SyncRun $run): void {
            if (! $run->exists || in_array((string) $run->state, ['cancel_requested', 'cancelled'], true)) {
                return;
            }

            $persistedState = static::withoutGlobalScopes()
                ->whereKey($run->getKey())
                ->value('state');

            if ($persistedState === 'cancel_requested') {
                throw new SyncCancellationRequested((int) $run->getKey());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'resources' => 'array',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function batches()
    {
        return $this->hasMany(SyncBatch::class);
    }
}
