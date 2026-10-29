<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccountEmailRecipient extends DomainModel
{
    protected $fillable = [
        'email_address',
        'normalized_email_address',
        'display_name',
        'is_enabled',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
