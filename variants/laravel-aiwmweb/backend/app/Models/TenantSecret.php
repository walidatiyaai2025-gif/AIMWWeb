<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'encrypted_value'])]
#[Hidden(['encrypted_value'])]
class TenantSecret extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['encrypted_value' => 'encrypted'];
    }
}
