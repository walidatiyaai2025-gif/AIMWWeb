<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiUsageRecord extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'ai_provider_profile_id', 'provider_key', 'model_key', 'workflow',
        'input_units', 'output_units', 'estimated_cost', 'actual_cost', 'currency',
        'status', 'failure_kind', 'latency_ms', 'retry_count', 'correlation_id',
        'provider_request_id', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:6',
            'actual_cost' => 'decimal:6',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
