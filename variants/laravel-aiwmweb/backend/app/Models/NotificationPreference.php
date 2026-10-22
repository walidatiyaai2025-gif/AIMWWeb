<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'scope_key', 'category', 'channel', 'mode', 'locale'])]
class NotificationPreference extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [];
    }
}
