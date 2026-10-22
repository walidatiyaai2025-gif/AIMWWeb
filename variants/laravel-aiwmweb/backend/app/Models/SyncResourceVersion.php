<?php

namespace App\Models;

class SyncResourceVersion extends ContentDomainModel
{
    protected function casts(): array
    {
        return [
            'remote_modified_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'tombstoned_at' => 'immutable_datetime',
        ];
    }
}
