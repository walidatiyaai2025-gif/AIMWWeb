<?php

namespace App\Models;

class ConnectorPairing extends DomainModel
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
