<?php
namespace App\Billing;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Tenancy\TenantContext;
final class BillingMaintenanceService
{
    public function __construct(private readonly TenantContext $context,private readonly SubscriptionService $subscriptions,private readonly BillingProviderManager $providers) {}
    public function run(): array { $result=['tenants'=>0,'lifecycle_changes'=>0,'provider_reconciliations'=>0,'reconciliation_failures'=>0]; foreach(Tenant::query()->cursor()as$tenant){$this->context->activate($tenant);try{$result['tenants']++;$result['lifecycle_changes']+=$this->subscriptions->expireTrialsAndGrace();$result['lifecycle_changes']+=$this->subscriptions->applyDueChanges();$s=TenantSubscription::query()->first();if($s?->provider&&$s->encrypted_provider_subscription_id&&$this->providers->for($s->provider)->configured()){try{$this->subscriptions->reconcile($s);$result['provider_reconciliations']++;}catch(\Throwable){$result['reconciliation_failures']++;}}}finally{$this->context->forget();}}return$result; }
}
