<?php
namespace App\Billing;
use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\QuotaExceededException;
use App\Models\TenantUsageCounter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
final class UsageQuotaService
{
    public function __construct(private readonly TenantContext $context,private readonly EntitlementService $entitlements,private readonly BillingAuditLogger $audit) {}
    private function window(): array { $now=now(); return [$now->copy()->startOfMonth(),$now->copy()->endOfMonth(),$now->format('Y-m')]; }
    public function consume(string $metric,int $amount=1): TenantUsageCounter
    {
        if($amount<1)throw new \InvalidArgumentException('Quota consumption must be positive.'); if(!$this->entitlements->plan())throw new EntitlementDeniedException('No active subscription grants quota usage.');
        $limit=$this->entitlements->limit($metric); [$start,$end,$key]=$this->window(); $tenantId=$this->context->id();
        return DB::transaction(function()use($metric,$amount,$limit,$start,$end,$key,$tenantId){ TenantUsageCounter::query()->insertOrIgnore(['tenant_id'=>$tenantId,'metric'=>$metric,'period_key'=>$key,'amount_used'=>0,'limit_snapshot'=>$limit,'period_started_at'=>$start,'period_ends_at'=>$end,'created_at'=>now(),'updated_at'=>now()]); $q=TenantUsageCounter::query()->where('metric',$metric)->where('period_key',$key); if($limit!==null)$q->where('amount_used','<=',max(0,$limit-$amount)); if($q->increment('amount_used',$amount,['updated_at'=>now()])!==1)throw new QuotaExceededException("Quota exceeded: {$metric}"); $c=TenantUsageCounter::query()->where('metric',$metric)->where('period_key',$key)->firstOrFail(); $this->audit->record('billing.quota.consumed',['metric'=>$metric,'amount'=>$amount,'used'=>$c->amount_used,'limit'=>$limit],'usage',$c->id); return $c; });
    }
    public function reconcile(string $metric,int $observed): TenantUsageCounter { if($observed<0)throw new \InvalidArgumentException('Observed usage cannot be negative.'); if(!$this->entitlements->plan())throw new EntitlementDeniedException('No active subscription grants quota usage.'); $limit=$this->entitlements->limit($metric); [$start,$end,$key]=$this->window(); TenantUsageCounter::query()->updateOrCreate(['metric'=>$metric,'period_key'=>$key],['amount_used'=>$observed,'limit_snapshot'=>$limit,'period_started_at'=>$start,'period_ends_at'=>$end]); $c=TenantUsageCounter::query()->where('metric',$metric)->where('period_key',$key)->firstOrFail(); $this->audit->record('billing.quota.reconciled',['metric'=>$metric,'used'=>$observed,'limit'=>$limit],'usage',$c->id); return $c; }
    public function remaining(string $metric): ?int { $limit=$this->entitlements->limit($metric); if($limit===null)return null; [,,$key]=$this->window(); $used=(int)TenantUsageCounter::query()->where('metric',$metric)->where('period_key',$key)->value('amount_used'); return max(0,$limit-$used); }
    public function snapshot(): array { [,,$key]=$this->window(); return TenantUsageCounter::query()->where('period_key',$key)->get()->map(fn($c)=>['metric'=>$c->metric,'used'=>$c->amount_used,'limit'=>$this->entitlements->limit($c->metric),'remaining'=>($l=$this->entitlements->limit($c->metric))===null?null:max(0,$l-$c->amount_used),'period_ends_at'=>$c->period_ends_at?->toAtomString()])->values()->all(); }
}
