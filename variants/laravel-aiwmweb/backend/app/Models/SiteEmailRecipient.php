<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteEmailRecipient extends DomainModel
{
    protected $fillable = [
        'site_id',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
