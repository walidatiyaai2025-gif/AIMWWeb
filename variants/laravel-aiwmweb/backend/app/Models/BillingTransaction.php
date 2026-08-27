<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class BillingTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_subscription_id', 'provider', 'provider_transaction_hash', 'encrypted_provider_transaction_id', 'type', 'status', 'amount_minor', 'currency', 'occurred_at', 'metadata'];

    protected function casts(): array
    {
        return ['encrypted_provider_transaction_id' => 'encrypted', 'amount_minor' => 'integer', 'occurred_at' => 'datetime', 'metadata' => 'array'];
    }
}
