<?php

namespace App\Models;

class Approval extends DomainModel
{
    protected function casts(): array
    {
        return ['before_state' => 'array', 'proposed_state' => 'array', 'decided_at' => 'datetime'];
    }
}
