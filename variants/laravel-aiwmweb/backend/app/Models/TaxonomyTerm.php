<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaxonomyTerm extends ContentDomainModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'remote_modified_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }

    public function contentItems(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class, 'content_taxonomy')->withPivot(['tenant_id', 'site_id']);
    }
}
