<?php

namespace App\Models;

class SyncWebhookEvent extends ContentDomainModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
