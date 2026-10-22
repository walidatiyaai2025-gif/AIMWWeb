<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SiteCredential extends DomainModel
{
    protected $hidden = ['secret_value'];

    protected function casts(): array
    {
        return [
            'secret_value' => 'encrypted',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
