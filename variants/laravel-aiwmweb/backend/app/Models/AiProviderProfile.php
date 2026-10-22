<?php

namespace App\Models;

use App\AI\Platform\Enums\ProviderReadiness;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProviderProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'provider_key', 'adapter_key', 'display_name', 'endpoint', 'default_model',
        'enabled', 'priority', 'timeout_seconds', 'max_attempts', 'automatic_failover',
        'limits', 'settings', 'readiness_state', 'readiness_checked_at',
        'readiness_error', 'last_rate_limited_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'automatic_failover' => 'boolean',
            'limits' => 'array',
            'settings' => 'array',
            'readiness_state' => ProviderReadiness::class,
            'readiness_checked_at' => 'immutable_datetime',
            'last_rate_limited_at' => 'immutable_datetime',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModelProfile::class);
    }

    public function secretName(): string
    {
        return "ai.provider.{$this->id}.api_key";
    }
}
