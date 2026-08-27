<?php

namespace App\Models;

class SiteDiagnostic extends DomainModel
{
    protected function casts(): array
    {
        return [
            'capability_summary' => 'array',
            'health' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
