<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class BillingSubscriptionChange extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_subscription_id', 'from_billing_plan_id', 'to_billing_plan_id', 'kind', 'status', 'effective_at', 'blocked_reason', 'provider_requested_at', 'completed_at'];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'provider_requested_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
