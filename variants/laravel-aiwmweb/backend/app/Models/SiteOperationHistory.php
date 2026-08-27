<?php

namespace App\Models;

class SiteOperationHistory extends DomainModel
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
