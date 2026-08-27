<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Hidden(['encrypted_secret'])]
class Connector extends DomainModel
{
    protected function casts(): array
    {
        return ['encrypted_secret' => 'encrypted', 'capabilities' => 'array', 'enabled_scopes' => 'array', 'verified_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
