<?php

namespace App\Models;

class SeoFinding extends DomainModel
{
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
