<?php

namespace App\Billing;

use App\Billing\Enums\SubscriptionState;
use App\Billing\Exceptions\BillingConflictException;
use App\Billing\Providers\PayPalProvider;
use App\Models\BillingAudit;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\DB;

final class PayPalSubscriptionReactivationService
{
    private const REQUESTED_ACTION = 'billing.reactivation.requested';

    public function __construct(
        private readonly SubscriptionStateMachine $states,
        private readonly BillingProviderManager $providers,
        private readonly BillingAuditLogger $audit,
    ) {}

    /** @return array{request_status:string,subscription:TenantSubscription} */
    public function request(): array
    {
        return DB::transaction(function (): array {
            $subscription = TenantSubscription::query()->lockForUpdate()->firstOrFail();

            if ($subscription->provider !== 'paypal' || ! filled($subscription->encrypted_provider_subscription_id)) {
                throw new BillingConflictException('PayPal reactivation is unavailable for this subscription.');
            }

            if ($subscription->state === SubscriptionState::CANCELLED) {
                throw new BillingConflictException('A permanently cancelled subscription cannot be reactivated.');
            }

            $provider = $this->providers->for('paypal');
            $snapshot = $provider->reconcile($subscription);
            $this->applyAuthoritativeSnapshot($subscription, $snapshot);

            if ($subscription->state === SubscriptionState::ACTIVE) {
                return $this->result('provider_confirmed', $subscription);
            }

            if ($subscription->state !== SubscriptionState::SUSPENDED) {
                throw new BillingConflictException('PayPal reactivation is available only for a suspended subscription.');
            }

            if ($this->wasAlreadyAccepted($subscription)) {
                return $this->result('provider_accepted', $subscription);
            }

            if (! $provider instanceof PayPalProvider && ! method_exists($provider, 'reactivateSubscription')) {
                throw new BillingConflictException('PayPal reactivation is unavailable from the configured provider adapter.');
            }

            $provider->reactivateSubscription($subscription);

            $this->audit->record(self::REQUESTED_ACTION, [
                'provider' => 'paypal',
                'state' => $subscription->state->value,
                'provider_state_authoritative' => true,
            ], 'subscription', $subscription->id);

            return $this->result('provider_accepted', $subscription);
        }, 3);
    }

    private function wasAlreadyAccepted(TenantSubscription $subscription): bool
    {
        return BillingAudit::query()
            ->where('action', self::REQUESTED_ACTION)
            ->where('subject_type', 'subscription')
            ->where('subject_id', $subscription->id)
            ->exists();
    }

    private function applyAuthoritativeSnapshot(TenantSubscription $subscription, array $snapshot): void
    {
        $providerStatus = strtoupper((string) ($snapshot['status'] ?? ''));
        $target = match ($providerStatus) {
            'ACTIVE' => SubscriptionState::ACTIVE,
            'SUSPENDED' => SubscriptionState::SUSPENDED,
            'CANCELLED' => SubscriptionState::CANCELLED,
            'EXPIRED' => SubscriptionState::EXPIRED,
            default => null,
        };

        if ($target !== null && $target !== $subscription->state) {
            $this->states->assert($subscription->state, $target);
            $subscription->state = $target;
        }

        $subscription->last_provider_event_at = $snapshot['occurred_at'] ?? now();
        $subscription->save();

        $this->audit->record('billing.reconciled', [
            'provider_status' => $providerStatus,
            'state' => $subscription->state->value,
        ], 'subscription', $subscription->id, null, true);
    }

    /** @return array{request_status:string,subscription:TenantSubscription} */
    private function result(string $requestStatus, TenantSubscription $subscription): array
    {
        return [
            'request_status' => $requestStatus,
            'subscription' => $subscription->fresh(),
        ];
    }
}
