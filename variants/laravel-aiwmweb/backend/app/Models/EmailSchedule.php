<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['site_id', 'name', 'template_stable_id', 'recipient', 'locale', 'variables', 'enabled', 'frequency', 'timezone_id', 'time_of_day', 'weekday', 'month_day', 'retry_count', 'retry_delay_minutes', 'interval_minutes', 'next_run_at', 'last_run_at'])]
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
            'weekday' => 'integer',
            'month_day' => 'integer',
            'retry_count' => 'integer',
            'retry_delay_minutes' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }
}
