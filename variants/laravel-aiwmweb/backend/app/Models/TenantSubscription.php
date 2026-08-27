<?php
namespace App\Models;
use App\Billing\Enums\SubscriptionState;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class TenantSubscription extends Model
{
    use BelongsToTenant;
    protected $fillable=['billing_plan_id','pending_billing_plan_id','state','provider','provider_subscription_hash','encrypted_provider_subscription_id','started_at','trial_started_at','trial_expires_at','current_period_start','current_period_end','grace_ends_at','cancel_at_period_end','cancelled_at','ended_at','plan_change_effective_at','last_provider_event_at','provider_metadata'];
    protected function casts(): array { return ['state'=>SubscriptionState::class,'encrypted_provider_subscription_id'=>'encrypted','started_at'=>'datetime','trial_started_at'=>'datetime','trial_expires_at'=>'datetime','current_period_start'=>'datetime','current_period_end'=>'datetime','grace_ends_at'=>'datetime','cancel_at_period_end'=>'boolean','cancelled_at'=>'datetime','ended_at'=>'datetime','plan_change_effective_at'=>'datetime','last_provider_event_at'=>'datetime','provider_metadata'=>'array']; }
    public function plan(): BelongsTo { return $this->belongsTo(BillingPlan::class,'billing_plan_id'); }
    public function pendingPlan(): BelongsTo { return $this->belongsTo(BillingPlan::class,'pending_billing_plan_id'); }
}
