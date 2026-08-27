<?php

namespace App\Models;

class Suggestion extends DomainModel
{
    protected function casts(): array
    {
        return ['before_state' => 'array', 'proposed_state' => 'array'];
    }
}
