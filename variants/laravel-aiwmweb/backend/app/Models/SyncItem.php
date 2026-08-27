<?php

namespace App\Models;

class SyncItem extends ContentDomainModel
{
    protected function casts(): array
    {
        return [
            'remote_payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
