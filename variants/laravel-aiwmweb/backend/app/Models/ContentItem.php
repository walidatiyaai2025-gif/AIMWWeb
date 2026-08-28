<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentItem extends ContentDomainModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'sticky' => 'boolean', 'stale' => 'boolean', 'published_at' => 'immutable_datetime', 'scheduled_at' => 'immutable_datetime', 'remote_modified_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class);
    }

    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'content_taxonomy')->withPivot(['tenant_id', 'site_id']);
    }
}
