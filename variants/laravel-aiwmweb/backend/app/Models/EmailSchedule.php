<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['site_id', 'name', 'template_stable_id', 'recipient', 'locale', 'variables', 'enabled', 'interval_minutes', 'next_run_at', 'last_run_at'])]
#[Hidden(['recipient', 'variables'])]
class EmailSchedule extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'variables' => 'array',
            'enabled' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }
}
