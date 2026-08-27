<?php

namespace App\Billing;

use App\Billing\Enums\SubscriptionState;
use App\Billing\Exceptions\BillingConflictException;
use App\Models\BillingPlan;
use App\Models\BillingProviderEvent;
use App\Models\BillingSubscriptionChange;
use App\Models\BillingTransaction;
use App\Models\Tenant;
use App\Models\TenantBillingProfile;
use App\Models\TenantSubscription;
use App\Models\TenantUsageCounter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SubscriptionService
{
    public function __construct(private readonly TenantContext $context, private readonly SubscriptionStateMachine $states, private readonly BillingProviderManager $providers, private readonly BillingAuditLogger $audit) {}

    public function startTrial(): TenantSubscription
    {
        return DB::transaction(function () {
            $profile = TenantBillingProfile::query()->lockForUpdate()->first();
            if ($profile?->trial_used_at) {
                throw new BillingConflictException('Trial has already been used for this tenant.');
            } if (TenantSubscription::query()->exists()) {
                throw new BillingConflictException('A subscription already exists for this tenant.');
            } $plan = BillingPlan::query()->where('code', 'free-trial')->where('enabled', true)->whereNull('retired_at')->firstOrFail();
            $profile ??= TenantBillingProfile::query()->create([]);
            $profile->forceFill(['trial_used_at' => now()])->save();
            $s = TenantSubscription::query()->create(['billing_plan_id' => $plan->id, 'state' => SubscriptionState::TRIALING, 'started_at' => now(), 'trial_started_at' => now(), 'trial_expires_at' => now()->addDays($plan->trial_period_days)]);
            $this->audit->record('billing.trial.started', ['expires_at' => $s->trial_expires_at->toAtomString()], 'subscription', $s->id);

            return $s->load('plan');
        });
    }

    public function checkout(BillingPlan $plan): array
    {
        if (! $plan->enabled || $plan->retired_at || $plan->code === 'free-trial' || ! $plan->commerciallyConfigured()) {
            throw new BillingConflictException('Plan is not available for checkout.');
        } $s = TenantSubscription::query()->first();
        if ($s && ! in_array($s->state, [SubscriptionState::TRIALING, SubscriptionState::EXPIRED, SubscriptionState::CANCELLED], true)) {
            throw new BillingConflictException('Use plan change for an existing paid subscription.');
        } if (! $s) {
            $s = TenantSubscription::query()->create(['billing_plan_id' => $plan->id, 'state' => SubscriptionState::SUSPENDED, 'provider' => $plan->provider, 'started_at' => now()]);
        } else {
            $s->forceFill(['pending_billing_plan_id' => $plan->id, 'provider' => $plan->provider])->save();
        } $intent = $this->providers->for($plan->provider)->createSubscriptionIntent($s, $plan);
        $s->forceFill(['provider_subscription_hash' => hash('sha256', $intent['provider_subscription_id']), 'encrypted_provider_subscription_id' => $intent['provider_subscription_id'], 'pending_billing_plan_id' => $plan->id])->save();
        $this->audit->record('billing.checkout.created', ['plan' => $plan->code, 'provider' => $plan->provider, 'provider_status' => $intent['status']], 'subscription', $s->id);

        return ['approval_url' => $intent['approval_url'], 'status' => 'PENDING_PROVIDER_CONFIRMATION'];
    }

    public function cancel(): TenantSubscription
    {
        $s = TenantSubscription::query()->firstOrFail();
        if ($s->state === SubscriptionState::TRIALING) {
            $this->transition($s, SubscriptionState::CANCELLED);
            $s->forceFill(['cancelled_at' => now(), 'ended_at' => now()])->save();
        } elseif ($s->provider && $s->encrypted_provider_subscription_id) {
            $this->providers->for($s->provider)->cancelSubscription($s);
            $s->forceFill(['cancel_at_period_end' => true])->save();
        } else {
            throw new BillingConflictException('Subscription cannot be cancelled in its current state.');
        } $this->audit->record('billing.cancellation.requested', ['state' => $s->state->value, 'provider' => $s->provider], 'subscription', $s->id);

        return $s;
    }

    public function requestPlanChange(BillingPlan $target): BillingSubscriptionChange
    {
        $s = TenantSubscription::query()->with('plan')->firstOrFail();
        if (! in_array($s->state, [SubscriptionState::ACTIVE, SubscriptionState::GRACE, SubscriptionState::PAST_DUE], true)) {
            throw new BillingConflictException('Plan change is unavailable in the current subscription state.');
        } if (! $target->enabled || $target->retired_at || $target->code === 'free-trial' || ! $target->commerciallyConfigured()) {
            throw new BillingConflictException('Target plan is unavailable.');
        } if ($target->id === $s->billing_plan_id) {
            throw new BillingConflictException('Target plan is already active.');
        } $kind = (($target->price_minor ?? PHP_INT_MAX) >= ($s->plan->price_minor ?? PHP_INT_MAX)) ? 'upgrade' : 'downgrade';
        $violations = [];
        foreach (($target->limits ?? []) as $metric => $limit) {
            if ($limit === null) {
                continue;
            }$used = (int) TenantUsageCounter::query()->where('metric', $metric)->where('period_key', now()->format('Y-m'))->max('amount_used');
            if ($used > (int) $limit) {
                $violations[$metric] = ['used' => $used, 'limit' => (int) $limit];
            }
        } $effective = $kind === 'downgrade' ? ($s->current_period_end ?? now()) : now();
        $change = BillingSubscriptionChange::query()->create(['tenant_subscription_id' => $s->id, 'from_billing_plan_id' => $s->billing_plan_id, 'to_billing_plan_id' => $target->id, 'kind' => $kind, 'status' => $violations ? 'blocked' : ($kind === 'downgrade' ? 'scheduled' : 'provider_pending'), 'effective_at' => $effective, 'blocked_reason' => $violations ? json_encode($violations) : null]);
        if ($violations) {
            $this->audit->record('billing.plan_change.blocked', ['target' => $target->code, 'limits' => $violations], 'subscription_change', $change->id);

            return $change;
        } $s->forceFill(['pending_billing_plan_id' => $target->id, 'plan_change_effective_at' => $effective])->save();
        if ($kind === 'upgrade') {
            $this->providers->for($s->provider)->changeSubscription($s, $target);
            $change->forceFill(['provider_requested_at' => now()])->save();
        } $this->audit->record('billing.plan_change.requested', ['target' => $target->code, 'kind' => $kind, 'effective_at' => $effective->toAtomString()], 'subscription_change', $change->id);

        return $change;
    }

    public function applyDueChanges(): int
    {
        $count = 0;
        foreach (BillingSubscriptionChange::query()->where('status', 'scheduled')->where('effective_at', '<=', now())->get() as $change) {
            $s = TenantSubscription::query()->findOrFail($change->tenant_subscription_id);
            $plan = BillingPlan::query()->findOrFail($change->to_billing_plan_id);
            $this->providers->for($s->provider)->changeSubscription($s, $plan);
            $change->forceFill(['status' => 'provider_pending', 'provider_requested_at' => now()])->save();
            $count++;
        }

return $count;
    }

    public function handleWebhook(string $provider, Request $request): string
    {
        $n = $this->providers->for($provider)->verifyAndParseWebhook($request);
        if (! filled($n['id']) || ! filled($n['type'])) {
            throw new RuntimeException('Provider event is invalid.');
        } $hash = hash('sha256', $provider.':'.$n['id']);
        $event = BillingProviderEvent::query()->firstOrCreate(['event_hash' => $hash], ['provider' => $provider, 'event_type' => $n['type'], 'payload_hash' => $n['payload_hash'], 'verified_at' => now(), 'outcome' => 'received']);
        if ($event->processed_at) {
            return 'duplicate';
        } $raw = $n['provider_subscription_id'] ?? '';
        $s = $raw ? TenantSubscription::withoutGlobalScopes()->where('provider', $provider)->where('provider_subscription_hash', hash('sha256', $raw))->first() : null;
        if (! $s) {
            $event->forceFill(['processed_at' => now(), 'outcome' => 'unmatched'])->save();

            return 'unmatched';
        } $tenant = Tenant::query()->findOrFail($s->tenant_id);
        $this->context->activate($tenant);
        try {
            $this->applyProviderEvent($s, $n);
            $event->forceFill(['tenant_id' => $tenant->id, 'tenant_subscription_id' => $s->id, 'processed_at' => now(), 'outcome' => 'processed'])->save();

            return 'processed';
        } catch (\Throwable $e) {
            $event->forceFill(['tenant_id' => $tenant->id, 'tenant_subscription_id' => $s->id, 'processed_at' => now(), 'outcome' => 'failed', 'failure_class' => class_basename($e)])->save();
            throw $e;
        } finally {
            $this->context->forget();
        }
    }

    private function applyProviderEvent(TenantSubscription $s, array $e): void
    {
        $type = $e['type'];
        $target = null;
        if (str_contains($type, 'ACTIVATED')) {
            $target = SubscriptionState::ACTIVE;
        } elseif (str_contains($type, 'PAYMENT.FAILED')) {
            $target = SubscriptionState::PAST_DUE;
        } elseif (str_contains($type, 'SUSPENDED')) {
            $target = SubscriptionState::SUSPENDED;
        } elseif (str_contains($type, 'CANCELLED')) {
            $target = SubscriptionState::CANCELLED;
        } elseif (str_contains($type, 'EXPIRED')) {
            $target = SubscriptionState::EXPIRED;
        }if ($target) {
            if ($target === SubscriptionState::ACTIVE && $s->pending_billing_plan_id) {
                $s->billing_plan_id = $s->pending_billing_plan_id;
                $s->pending_billing_plan_id = null;
            }$this->transition($s, $target);
        }if (str_contains($type, 'UPDATED') && $s->pending_billing_plan_id) {
            $pending = BillingPlan::query()->find($s->pending_billing_plan_id);
            if ($pending && $pending->provider_plan_id === ($e['provider_plan_id'] ?? null)) {
                $s->billing_plan_id = $pending->id;
                $s->pending_billing_plan_id = null;
                $s->plan_change_effective_at = null;
                BillingSubscriptionChange::query()->where('tenant_subscription_id', $s->id)->where('status', 'provider_pending')->latest('id')->first()?->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            }
        }if (str_contains($type, 'PAYMENT') && ! str_contains($type, 'FAILED') && filled($e['transaction_id'] ?? null)) {
            BillingTransaction::query()->firstOrCreate(['provider' => $s->provider, 'provider_transaction_hash' => hash('sha256', $e['transaction_id'])], ['tenant_subscription_id' => $s->id, 'encrypted_provider_transaction_id' => $e['transaction_id'], 'type' => 'payment', 'status' => 'completed', 'amount_minor' => $e['amount_minor'] ?? null, 'currency' => $e['currency'] ?? null, 'occurred_at' => $e['occurred_at'] ?? now(), 'metadata' => ['event_type' => $type]]);
        }$s->forceFill(['last_provider_event_at' => $e['occurred_at'] ?? now()]);
        if ($target === SubscriptionState::CANCELLED) {
            $s->forceFill(['cancelled_at' => now(), 'ended_at' => now()]);
        }$s->save();
        $this->audit->record('billing.provider.event', ['provider' => $s->provider, 'event_type' => $type, 'state' => $s->state->value], 'subscription', $s->id, null, true);
    }

    public function reconcile(TenantSubscription $s): void
    {
        if (! $s->provider || ! $s->encrypted_provider_subscription_id) {
            return;
        }$data = $this->providers->for($s->provider)->reconcile($s);
        $status = strtoupper($data['status'] ?? '');
        $target = match ($status) {
            'ACTIVE' => SubscriptionState::ACTIVE,'SUSPENDED' => SubscriptionState::SUSPENDED,'CANCELLED' => SubscriptionState::CANCELLED,'EXPIRED' => SubscriptionState::EXPIRED,default => null
        };
        if ($target) {
            $this->transition($s, $target);
        }$s->forceFill(['last_provider_event_at' => $data['occurred_at'] ?? now()])->save();
        $this->audit->record('billing.reconciled', ['provider_status' => $status, 'state' => $s->state->value], 'subscription', $s->id, null, true);
    }

    public function expireTrialsAndGrace(): int
    {
        $count = 0;
        foreach (TenantSubscription::query()->where('state', SubscriptionState::TRIALING->value)->where('trial_expires_at', '<=', now())->get() as $s) {
            $this->transition($s, SubscriptionState::EXPIRED);
            $s->forceFill(['ended_at' => now()])->save();
            $this->audit->record('billing.trial.expired', [], 'subscription', $s->id, null, true);
            $count++;
        }foreach (TenantSubscription::query()->where('state', SubscriptionState::PAST_DUE->value)->with('plan')->get() as $s) {
            if ($s->plan->grace_period_days > 0) {
                $this->transition($s, SubscriptionState::GRACE);
                $s->forceFill(['grace_ends_at' => now()->addDays($s->plan->grace_period_days)])->save();
                $this->audit->record('billing.grace.started', ['grace_ends_at' => $s->grace_ends_at->toAtomString()], 'subscription', $s->id, null, true);
                $count++;
            }
        }foreach (TenantSubscription::query()->where('state', SubscriptionState::GRACE->value)->where('grace_ends_at', '<=', now())->get() as $s) {
            $this->transition($s, SubscriptionState::SUSPENDED);
            $s->save();
            $this->audit->record('billing.grace.expired',[],'subscription',$s->id,null,true);
            $count++;
        }

return $count;
    }

    private function transition(TenantSubscription $s,SubscriptionState $to): void
    {
        $this->states->assert($s->state,$to);
        $s->state = $to;
    }
}
