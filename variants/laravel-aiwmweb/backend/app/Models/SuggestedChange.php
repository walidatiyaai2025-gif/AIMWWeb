<?php

namespace App\Models;

class SuggestedChange extends DomainModel
{
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'requires_backup' => 'boolean',
            'requires_staging' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
