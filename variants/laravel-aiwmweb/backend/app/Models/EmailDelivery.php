<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['in_app_notification_id', 'mail_configuration_id', 'event_id', 'delivery_id', 'idempotency_key', 'recipient', 'recipient_hash', 'template_stable_id', 'locale', 'status', 'attempt_count', 'max_attempts', 'provider_message_id', 'failure_category', 'failure_message', 'variables', 'scheduled_for', 'sending_started_at', 'sent_at', 'failed_at'])]
#[Hidden(['recipient', 'variables'])]
class EmailDelivery extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'variables' => 'array',
            'scheduled_for' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
