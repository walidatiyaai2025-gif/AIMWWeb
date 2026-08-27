<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'operation', 'request_hash', 'response', 'completed_at'])]
class IdempotencyKey extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['response' => 'array', 'completed_at' => 'datetime'];
    }
}
