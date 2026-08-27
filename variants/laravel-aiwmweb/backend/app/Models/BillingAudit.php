<?php
namespace App\Models;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;
final class BillingAudit extends Model
{
    use BelongsToTenant; public $timestamps=false; protected $fillable=['actor_user_id','action','subject_type','subject_id','metadata','occurred_at'];
    protected function casts(): array { return ['metadata'=>'array','occurred_at'=>'immutable_datetime']; }
    protected static function booted(): void { static::updating(fn()=>throw new LogicException('Billing audits are immutable.')); static::deleting(fn()=>throw new LogicException('Billing audits are immutable.')); }
}
