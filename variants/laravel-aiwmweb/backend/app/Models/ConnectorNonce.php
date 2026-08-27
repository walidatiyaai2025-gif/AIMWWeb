<?php

namespace App\Models;

class ConnectorNonce extends DomainModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
