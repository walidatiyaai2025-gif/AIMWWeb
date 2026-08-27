<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'ai_provider_profile_id', 'model_key', 'display_name', 'enabled', 'capabilities',
        'context_window', 'max_output_tokens', 'input_cost_per_million',
        'output_cost_per_million', 'currency', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'capabilities' => 'array',
            'metadata' => 'array',
            'input_cost_per_million' => 'decimal:6',
            'output_cost_per_million' => 'decimal:6',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProviderProfile::class, 'ai_provider_profile_id');
    }
}
