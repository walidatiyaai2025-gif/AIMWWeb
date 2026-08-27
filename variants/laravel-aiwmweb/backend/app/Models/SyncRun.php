<?php

namespace App\Models;

class SyncRun extends DomainModel
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
