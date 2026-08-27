<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class TenantUsageCounter extends Model
{
    use BelongsToTenant;

    protected $fillable = ['metric', 'period_key', 'amount_used', 'limit_snapshot', 'period_started_at', 'period_ends_at'];

    protected function casts(): array
    {
        return ['amount_used' => 'integer', 'limit_snapshot' => 'integer', 'period_started_at' => 'datetime', 'period_ends_at' => 'datetime'];
    }
}
