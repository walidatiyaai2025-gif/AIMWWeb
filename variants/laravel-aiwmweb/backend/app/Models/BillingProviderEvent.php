<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class BillingProviderEvent extends Model
{
    protected $fillable=['provider','event_hash','event_type','payload_hash','tenant_id','tenant_subscription_id','verified_at','processed_at','outcome','failure_class'];
    protected function casts(): array { return ['verified_at'=>'datetime','processed_at'=>'datetime']; }
}
