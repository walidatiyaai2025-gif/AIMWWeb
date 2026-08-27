<?php

namespace App\Models;

class Site extends DomainModel
{
    protected function casts(): array
    {
        return ['last_verified_at' => 'datetime', 'last_sync_at' => 'datetime'];
    }
}
