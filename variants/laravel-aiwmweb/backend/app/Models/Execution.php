<?php

namespace App\Models;

class Execution extends DomainModel
{
    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'concurrency_token' => 'integer',
            'cancelled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
