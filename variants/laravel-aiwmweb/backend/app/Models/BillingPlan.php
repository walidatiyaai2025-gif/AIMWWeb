<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BillingPlan extends Model
{
    protected $fillable = ['code', 'name', 'localized_name', 'description', 'price_minor', 'currency', 'billing_interval', 'trial_period_days', 'grace_period_days', 'enabled', 'retired_at', 'display_order', 'provider', 'provider_product_id', 'provider_plan_id', 'limits', 'entitlements'];

    protected function casts(): array
    {
        return ['localized_name' => 'array', 'limits' => 'array', 'entitlements' => 'array', 'enabled' => 'boolean', 'retired_at' => 'datetime', 'price_minor' => 'integer'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function commerciallyConfigured(): bool
    {
        return $this->code === 'free-trial' || ($this->price_minor !== null && filled($this->provider_plan_id));
    }
}
