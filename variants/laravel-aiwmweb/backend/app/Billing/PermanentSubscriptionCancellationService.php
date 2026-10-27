<?php

namespace App\Billing;

use App\Billing\Enums\SubscriptionState;
use App\Billing\Exceptions\BillingConflictException;
use App\Models\BillingAudit;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\DB;

final class PermanentSubscriptionCancellationService
{
    private const REQUESTED_ACTION = 'billing.cancellation.permanent_requested';

    private const ELIGIBLE_STATES = [
        SubscriptionState::ACTIVE,
        SubscriptionState::PAST_DUE,
        SubscriptionState::GRACE,
        SubscriptionState::SUSPENDED,
    ];

    public function __construct(
        private readonly SubscriptionStateMachine $states,
        private readonly BillingProviderManager $providers,
        private readonly BillingAuditLogger $audit,
    ) {}

    /**
     * Request irreversible provider cancellation without fabricating a local cancellation.
     *
     * The provider snapshot/webhook remains authoritative for the actual CANCELLED state.
     * A tenant-scoped immutable audit receipt makes accepted retries idempotent. Before a
     * provider retry we reconcile once so an accepted-but-ambiguous prior request cannot
     * be blindly submitted again if PayPal already reports the subscription cancelled.
     *
     * @return array{request_status:string,subscription:TenantSubscription}
     */
    public function request(): array
    {
        return DB::transaction(function (): array {
            $subscription = TenantSubscription::query()->lockForUpdate()->firstOrFail();

            $this->assertPayPalBound($subscription);

            if ($subscription->state === SubscriptionState::CANCELLED) {
                return $this->result('provider_confirmed', $subscription);
            }

            if ($this->wasAlreadyAccepted($subscription)) {
                return $this->result('provider_accepted', $subscription);
            }

            $this->assertEligible($subscription);

            $provider = $this->providers->for('paypal');
            $snapshot = $provider->reconcile($subscription);
            $this->applyAuthoritativeSnapshot($subscription, $snapshot);

            if ($subscription->state === SubscriptionState::CANCELLED) {
                return $this->result('provider_confirmed', $subscription);
            }

            $this->assertEligible($subscription);
            $provider->cancelSubscription($subscription);

            $this->audit->record(self::REQUESTED_ACTION, [
                'provider' => 'paypal',
                'state' => $subscription->state->value,
                'provider_state_authoritative' => true,
            ], 'subscription', $subscription->id);

            return $this->result('provider_accepted', $subscription);
        }, 3);
    }

    private function assertPayPalBound(TenantSubscription $subscription): void
    {
        if ($subscription->provider !== 'paypal' || ! filled($subscription->encrypted_provider_subscription_id)) {
            throw new BillingConflictException('Permanent PayPal cancellation is unavailable for this subscription.');
        }
    }

    private function assertEligible(TenantSubscription $subscription): void
    {
        if (! in_array($subscription->state, self::ELIGIBLE_STATES, true)) {
            throw new BillingConflictException('Permanent PayPal cancellation is unavailable in the current subscription state.');
        }
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

        if ($target !== null) {
            $this->states->assert($subscription->state, $target);
            $subscription->state = $target;
        }

        $subscription->last_provider_event_at = $snapshot['occurred_at'] ?? now();
        if ($target === SubscriptionState::CANCELLED) {
            $subscription->cancelled_at = $subscription->cancelled_at ?? now();
            $subscription->ended_at = $subscription->ended_at ?? now();
        }
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
