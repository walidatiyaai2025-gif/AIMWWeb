<?php

namespace App\Models;

class SyncSiteLease extends ContentDomainModel
{
    protected function casts(): array
    {
        return ['leased_until' => 'immutable_datetime'];
    }
}
