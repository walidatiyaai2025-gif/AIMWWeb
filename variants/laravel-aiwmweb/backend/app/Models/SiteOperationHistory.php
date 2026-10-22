<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteOperationHistory extends DomainModel
{
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
