<?php
namespace App\Models;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
final class TenantBillingProfile extends Model
{
    use BelongsToTenant; protected $fillable=['trial_used_at','provider_customer_hash','encrypted_provider_customer_id'];
    protected function casts(): array { return ['trial_used_at'=>'datetime','encrypted_provider_customer_id'=>'encrypted']; }
}
