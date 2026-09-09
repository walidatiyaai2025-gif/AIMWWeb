<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'event_type', 'source', 'received_at'])]
class NotificationEventReceipt extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
