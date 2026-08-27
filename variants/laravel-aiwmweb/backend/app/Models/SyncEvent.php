<?php

namespace App\Models;

class SyncEvent extends ContentDomainModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
