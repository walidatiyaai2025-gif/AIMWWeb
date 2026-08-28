<?php

namespace App\Models;

class ContentSyncState extends ContentDomainModel
{
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'last_remote_modified_at' => 'immutable_datetime'];
    }
}
