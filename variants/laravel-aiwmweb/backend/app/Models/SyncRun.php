<?php

namespace App\Models;

class SyncRun extends ContentDomainModel
{
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
