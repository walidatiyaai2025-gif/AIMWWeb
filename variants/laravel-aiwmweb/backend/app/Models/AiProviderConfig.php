<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Hidden(['encrypted_api_key'])]
class AiProviderConfig extends DomainModel
{
    protected function casts(): array
    {
        return ['encrypted_api_key' => 'encrypted', 'enabled' => 'boolean'];
    }
}
