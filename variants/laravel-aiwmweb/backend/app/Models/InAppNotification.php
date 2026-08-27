<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'notification_id', 'event_id', 'category', 'severity', 'source', 'title', 'message', 'deep_link', 'mandatory', 'locale', 'delivery_mode', 'metadata', 'read_at'])]
class InAppNotification extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['mandatory' => 'boolean', 'metadata' => 'array', 'read_at' => 'datetime'];
    }
}
