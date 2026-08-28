<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRevision extends ContentDomainModel
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'remote_modified_at' => 'immutable_datetime'];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
