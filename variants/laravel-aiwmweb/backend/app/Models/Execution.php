<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Execution extends DomainModel
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'result' => 'array',
        'progress_percent' => 'integer',
        'concurrency_token' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
