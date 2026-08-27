<?php

namespace App\Models;

class SyncTombstone extends ContentDomainModel
{
    protected function casts(): array
    {
        return [
            'first_missing_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'confirmed_deleted_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }
}
