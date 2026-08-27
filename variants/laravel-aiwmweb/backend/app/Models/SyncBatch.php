<?php

namespace App\Models;

class SyncBatch extends ContentDomainModel
{
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
