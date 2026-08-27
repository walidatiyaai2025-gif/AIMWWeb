<?php

namespace App\Models;

class SeoAudit extends DomainModel
{
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
