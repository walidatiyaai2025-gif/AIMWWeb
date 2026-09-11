<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Execution extends DomainModel
{
    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'concurrency_token' => 'integer',
            'cancelled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
