<?php

namespace App\Http\Controllers;

use App\Billing\BillingProviderManager;
use App\Billing\Enums\SubscriptionState;
use App\Billing\SubscriptionStateMachine;
use App\Models\BillingAudit;
use App\Models\BillingPlan;
use App\Models\BillingSubscriptionChange;
use App\Models\IdempotencyKey;
use App\Models\TenantSubscription;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdminBillingSupportController extends Controller
{
    public const REACTIVATE_OPERATION_ID = 'AIMW-BILL-29A4267B87';

    public const GRANT_GRACE_OPERATION_ID = 'AIMW-BILL-5A0140C699';

    public const RECONCILE_OPERATION_ID = 'AIMW-BILL-7ECC5F8CBA';

    public const SEARCH_OPERATION_ID = 'AIMW-BILL-9414B3FFEF';

    public const SUSPEND_OPERATION_ID = 'AIMW-BILL-602CCA4A55';

    private const IDEMPOTENCY_OPERATION = 'billing.support.reactivate';

    private const GRANT_GRACE_IDEMPOTENCY_OPERATION = 'billing.support.grant-grace';

    private const SUSPEND_IDEMPOTENCY_OPERATION = 'billing.support.suspend';

    private const RECONCILE_IDEMPOTENCY_OPERATION = 'billing.support.reconcile';

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'account_id' => ['prohibited'],
            'provider_subscription_id' => ['prohibited'],
            'provider_subscription_hash' => ['prohibited'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));
        $tenant = $tenantContext->tenant();
        $subscriptionsQuery = TenantSubscription::query()->with('plan:id,code,name');

        $matchesActiveAccount = $search !== '' && (
            (ctype_digit($search) && (int) $search === (int) $tenant->getKey())
            || strcasecmp($search, (string) $tenant->slug) === 0
        );

        if ($search !== '' && ! $matchesActiveAccount) {
            $normalized = mb_strtolower($search);
            $subscriptionsQuery->where(function ($builder) use ($search, $normalized): void {
                if (ctype_digit($search)) {
                    $builder->orWhere('tenant_subscriptions.id', (int) $search);
                }

                $builder
                    ->orWhere('tenant_subscriptions.provider_subscription_hash', hash('sha256', $search))
                    ->orWhereExists(function ($memberships) use ($normalized): void {
                        $memberships
                            ->selectRaw('1')
                            ->from('tenant_memberships')
                            ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                            ->whereColumn('tenant_memberships.tenant_id', 'tenant_subscriptions.tenant_id')
                            ->where('tenant_memberships.status', 'active')
                            ->where(function ($identity) use ($normalized): void {
                                $identity
                                    ->whereRaw('LOWER(users.username) = ?', [$normalized])
                                    ->orWhereRaw('LOWER(users.email) = ?', [$normalized])
                                    ->orWhereRaw('LOWER(users.name) = ?', [$normalized]);
                            });
                    });
            });
        }

        $subscriptions = $subscriptionsQuery
            ->latest('updated_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (TenantSubscription $subscription): array => [
                ...$this->snapshot($subscription),
                'account_id' => (int) $tenant->getKey(),
                'account_slug' => (string) $tenant->slug,
                'plan_code' => $subscription->plan?->code,
                'plan_name' => $subscription->plan?->name,
                'provider' => $subscription->provider,
                'masked_provider_subscription_reference' => $this->maskedProviderReference(
                    $subscription->encrypted_provider_subscription_id,
                ),
                'current_period_end' => $subscription->current_period_end?->utc()->toIso8601String(),
                'last_provider_event_at' => $subscription->last_provider_event_at?->utc()->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'operation_id' => self::SEARCH_OPERATION_ID,
            'count' => $subscriptions->count(),
            'data' => $subscriptions,
        ]);
    }

    public function suspend(
        Request $request,
        string $tenant,
        int $subscription,
        SubscriptionStateMachine $states,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'tenant_id' => ['prohibited'],
            'account_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_subscription_id' => ['prohibited'],
            'provider_subscription_hash' => ['prohibited'],
            'encrypted_provider_subscription_id' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'state' => ['prohibited'],
            'grace_ends_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'ended_at' => ['prohibited'],
        ]);

        $reason = trim((string) $data['reason']);
        abort_if(mb_strlen($reason) < 5, 422, 'Support reason must contain at least 5 characters.');

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey === '', 422, 'Idempotency-Key is required.');
        abort_if(strlen($idempotencyKey) > 128, 422, 'Idempotency-Key must not exceed 128 characters.');

        $requestHash = hash('sha256', json_encode([
            'subscription_id' => $subscription,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($request, $subscription, $states, $reason, $idempotencyKey, $requestHash): array {
            $keyRecord = IdempotencyKey::query()
                ->where('operation', self::SUSPEND_IDEMPOTENCY_OPERATION)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($keyRecord) {
                abort_unless(
                    hash_equals((string) $keyRecord->request_hash, $requestHash),
                    409,
                    'Idempotency key was already used with a different request.',
                );

                if ($keyRecord->completed_at && is_array($keyRecord->response)) {
                    return $keyRecord->response;
                }
            } else {
                $keyRecord = IdempotencyKey::query()->create([
                    'key' => $idempotencyKey,
                    'operation' => self::SUSPEND_IDEMPOTENCY_OPERATION,
                    'request_hash' => $requestHash,
                ]);
            }

            $target = TenantSubscription::query()->lockForUpdate()->findOrFail($subscription);
            abort_if(
                $target->state === SubscriptionState::SUSPENDED,
                409,
                'Subscription access is already suspended.',
            );

            $before = $target->state;
            $providerBefore = $target->provider;
            $providerHashBefore = $target->provider_subscription_hash;
            $encryptedProviderBefore = $target->encrypted_provider_subscription_id;
            try {
                $states->assert($before, SubscriptionState::SUSPENDED);
            } catch (Throwable) {
                abort(409, "Subscription transition {$before->value} -> SUSPENDED is not allowed.");
            }

            $target->forceFill([
                'state' => SubscriptionState::SUSPENDED,
                'grace_ends_at' => null,
            ])->save();

            $persisted = TenantSubscription::query()->findOrFail((int) $target->getKey());
            abort_unless(
                $persisted->state === SubscriptionState::SUSPENDED
                && $persisted->provider === $providerBefore
                && $persisted->provider_subscription_hash === $providerHashBefore
                && $persisted->encrypted_provider_subscription_id === $encryptedProviderBefore,
                409,
                'Persisted billing state did not reconcile after support suspension.',
            );

            BillingAudit::query()->create([
                'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                'action' => 'billing.support.suspended',
                'subject_type' => 'subscription',
                'subject_id' => (string) $persisted->getKey(),
                'metadata' => [
                    'operation_id' => self::SUSPEND_OPERATION_ID,
                    'reason' => $reason,
                    'from_state' => $before->value,
                    'to_state' => $persisted->state->value,
                    'provider_bound' => filled($persisted->provider)
                        || filled($persisted->provider_subscription_hash)
                        || filled($persisted->encrypted_provider_subscription_id),
                    'payment_success_recorded' => false,
                    'provider_mutated' => false,
                    'request_id' => $request->header('X-Request-Id'),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                ],
                'occurred_at' => now(),
            ]);

            $response = [
                'operation_id' => self::SUSPEND_OPERATION_ID,
                'mutation' => 'suspended',
                'data' => $this->snapshot($persisted),
            ];

            $keyRecord->response = $response;
            $keyRecord->completed_at = now();
            $keyRecord->save();

            return $response;
        }, 3);

        $authoritative = TenantSubscription::query()->findOrFail((int) $result['data']['id']);
        abort_unless(
            $authoritative->state === SubscriptionState::SUSPENDED,
            409,
            'Persisted billing state did not reconcile after support suspension.',
        );
        $result['data'] = $this->snapshot($authoritative);

        return response()->json($result);
    }

    public function reactivate(
        Request $request,
        string $tenant,
        int $subscription,
        SubscriptionStateMachine $states,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_subscription_id' => ['prohibited'],
            'provider_subscription_hash' => ['prohibited'],
            'payment_status' => ['prohibited'],
        ]);

        $reason = trim((string) $data['reason']);
        abort_if(mb_strlen($reason) < 5, 422, 'Support reason must contain at least 5 characters.');

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey === '', 422, 'Idempotency-Key is required.');
        abort_if(strlen($idempotencyKey) > 128, 422, 'Idempotency-Key must not exceed 128 characters.');

        $requestHash = hash('sha256', json_encode([
            'subscription_id' => $subscription,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($request, $subscription, $states, $reason, $idempotencyKey, $requestHash): array {
            $keyRecord = IdempotencyKey::query()
                ->where('operation', self::IDEMPOTENCY_OPERATION)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($keyRecord) {
                abort_unless(
                    hash_equals((string) $keyRecord->request_hash, $requestHash),
                    409,
                    'Idempotency key was already used with a different request.',
                );

                if ($keyRecord->completed_at && is_array($keyRecord->response)) {
                    return $keyRecord->response;
                }
            } else {
                $keyRecord = IdempotencyKey::query()->create([
                    'key' => $idempotencyKey,
                    'operation' => self::IDEMPOTENCY_OPERATION,
                    'request_hash' => $requestHash,
                ]);
            }

            $target = TenantSubscription::query()->lockForUpdate()->findOrFail($subscription);

            abort_unless(
                $target->state === SubscriptionState::SUSPENDED,
                409,
                'Only a suspended local subscription can be reactivated by support.',
            );
            abort_if(
                filled($target->provider)
                    || filled($target->provider_subscription_hash)
                    || filled($target->encrypted_provider_subscription_id),
                409,
                'Provider-backed subscriptions require authoritative provider reconciliation.',
            );

            $before = $target->state->value;
            $states->assert($target->state, SubscriptionState::ACTIVE);

            $target->forceFill([
                'state' => SubscriptionState::ACTIVE,
                'grace_ends_at' => null,
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'ended_at' => null,
            ])->save();

            $persisted = TenantSubscription::query()->findOrFail((int) $target->getKey());

            BillingAudit::query()->create([
                'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                'action' => 'billing.support.reactivated',
                'subject_type' => 'subscription',
                'subject_id' => (string) $persisted->getKey(),
                'metadata' => [
                    'operation_id' => self::REACTIVATE_OPERATION_ID,
                    'reason' => $reason,
                    'from_state' => $before,
                    'to_state' => $persisted->state->value,
                    'local_access_only' => true,
                    'payment_success_recorded' => false,
                    'provider_mutated' => false,
                    'request_id' => $request->header('X-Request-Id'),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                ],
                'occurred_at' => now(),
            ]);

            $response = [
                'operation_id' => self::REACTIVATE_OPERATION_ID,
                'mutation' => 'reactivated',
                'data' => $this->snapshot($persisted),
            ];

            $keyRecord->response = $response;
            $keyRecord->completed_at = now();
            $keyRecord->save();

            return $response;
        }, 3);

        $authoritative = TenantSubscription::query()->findOrFail((int) $result['data']['id']);
        abort_unless(
            $authoritative->state === SubscriptionState::ACTIVE
            && blank($authoritative->provider)
            && blank($authoritative->provider_subscription_hash)
            && blank($authoritative->encrypted_provider_subscription_id),
            409,
            'Persisted billing state did not reconcile after support reactivation.',
        );

        $result['data'] = $this->snapshot($authoritative);

        return response()->json($result);
    }

    public function grantGrace(
        Request $request,
        string $tenant,
        int $subscription,
        SubscriptionStateMachine $states,
    ): JsonResponse {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:90'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_subscription_id' => ['prohibited'],
            'provider_subscription_hash' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'grace_ends_at' => ['prohibited'],
        ]);

        $days = (int) $data['days'];
        $reason = trim((string) $data['reason']);
        abort_if(mb_strlen($reason) < 5, 422, 'Support reason must contain at least 5 characters.');

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey === '', 422, 'Idempotency-Key is required.');
        abort_if(strlen($idempotencyKey) > 128, 422, 'Idempotency-Key must not exceed 128 characters.');

        $requestHash = hash('sha256', json_encode([
            'subscription_id' => $subscription,
            'days' => $days,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($request, $subscription, $states, $days, $reason, $idempotencyKey, $requestHash): array {
            $keyRecord = IdempotencyKey::query()
                ->where('operation', self::GRANT_GRACE_IDEMPOTENCY_OPERATION)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($keyRecord) {
                abort_unless(
                    hash_equals((string) $keyRecord->request_hash, $requestHash),
                    409,
                    'Idempotency key was already used with a different request.',
                );

                if ($keyRecord->completed_at && is_array($keyRecord->response)) {
                    return $keyRecord->response;
                }
            } else {
                $keyRecord = IdempotencyKey::query()->create([
                    'key' => $idempotencyKey,
                    'operation' => self::GRANT_GRACE_IDEMPOTENCY_OPERATION,
                    'request_hash' => $requestHash,
                ]);
            }

            $target = TenantSubscription::query()->lockForUpdate()->findOrFail($subscription);

            abort_if(
                in_array($target->state, [SubscriptionState::CANCELLED, SubscriptionState::EXPIRED], true),
                409,
                'Grace cannot be granted to a cancelled or expired subscription.',
            );

            $now = now()->startOfSecond();
            $previousState = $target->state;
            $previousGraceEndsAt = $target->grace_ends_at?->copy();

            if ($target->state === SubscriptionState::GRACE) {
                $base = $previousGraceEndsAt && $previousGraceEndsAt->greaterThan($now)
                    ? $previousGraceEndsAt->copy()
                    : $now->copy();
                $graceEndsAt = $base->addDays($days);
                $mutation = 'grace_extended';
                $auditAction = 'billing.support.grace_extended';
            } else {
                try {
                    $states->assert($target->state, SubscriptionState::GRACE);
                } catch (Throwable) {
                    abort(409, "Subscription transition {$target->state->value} -> GRACE is not allowed.");
                }

                $graceEndsAt = $now->copy()->addDays($days);
                $mutation = 'grace_granted';
                $auditAction = 'billing.support.grace_granted';
            }

            $target->forceFill([
                'state' => SubscriptionState::GRACE,
                'grace_ends_at' => $graceEndsAt,
            ])->save();

            $persisted = TenantSubscription::query()->findOrFail((int) $target->getKey());
            abort_unless(
                $persisted->state === SubscriptionState::GRACE
                    && $persisted->grace_ends_at
                    && $persisted->grace_ends_at->equalTo($graceEndsAt),
                409,
                'Persisted billing state did not reconcile after support grace mutation.',
            );

            BillingAudit::query()->create([
                'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                'action' => $auditAction,
                'subject_type' => 'subscription',
                'subject_id' => (string) $persisted->getKey(),
                'metadata' => [
                    'operation_id' => self::GRANT_GRACE_OPERATION_ID,
                    'reason' => $reason,
                    'additional_days' => $days,
                    'from_state' => $previousState->value,
                    'to_state' => $persisted->state->value,
                    'previous_grace_ends_at' => $previousGraceEndsAt?->utc()->toIso8601String(),
                    'grace_ends_at' => $persisted->grace_ends_at?->utc()->toIso8601String(),
                    'payment_success_recorded' => false,
                    'provider_mutated' => false,
                    'request_id' => $request->header('X-Request-Id'),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                ],
                'occurred_at' => now(),
            ]);

            $response = [
                'operation_id' => self::GRANT_GRACE_OPERATION_ID,
                'mutation' => $mutation,
                'data' => $this->snapshot($persisted),
            ];

            $keyRecord->response = $response;
            $keyRecord->completed_at = now();
            $keyRecord->save();

            return $response;
        }, 3);

        $authoritative = TenantSubscription::query()->findOrFail((int) $result['data']['id']);
        $expectedGraceEndsAt = $result['data']['grace_ends_at'] ?? null;
        abort_unless(
            $authoritative->state === SubscriptionState::GRACE
                && $authoritative->grace_ends_at
                && is_string($expectedGraceEndsAt)
                && $authoritative->grace_ends_at->greaterThanOrEqualTo($expectedGraceEndsAt),
            409,
            'Persisted billing state did not reconcile after support grace mutation.',
        );

        $result['data'] = $this->snapshot($authoritative);

        return response()->json($result);
    }

    public function reconcilePayPal(
        Request $request,
        string $tenant,
        int $subscription,
        BillingProviderManager $providers,
        SubscriptionStateMachine $states,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_subscription_id' => ['prohibited'],
            'provider_subscription_hash' => ['prohibited'],
            'provider_plan_id' => ['prohibited'],
            'provider_status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'billing_plan_id' => ['prohibited'],
            'current_period_start' => ['prohibited'],
            'current_period_end' => ['prohibited'],
            'last_provider_event_at' => ['prohibited'],
        ]);

        $reason = trim((string) $data['reason']);
        abort_if(mb_strlen($reason) < 5, 422, 'Support reason must contain at least 5 characters.');

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($idempotencyKey === '', 422, 'Idempotency-Key is required.');
        abort_if(strlen($idempotencyKey) > 128, 422, 'Idempotency-Key must not exceed 128 characters.');

        $requestHash = hash('sha256', json_encode([
            'subscription_id' => $subscription,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        $prepared = DB::transaction(function () use ($subscription, $idempotencyKey, $requestHash): array {
            $keyRecord = IdempotencyKey::query()
                ->where('operation', self::RECONCILE_IDEMPOTENCY_OPERATION)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($keyRecord) {
                abort_unless(
                    hash_equals((string) $keyRecord->request_hash, $requestHash),
                    409,
                    'Idempotency key was already used with a different request.',
                );

                if ($keyRecord->completed_at && is_array($keyRecord->response)) {
                    return ['replay' => $keyRecord->response];
                }

                abort(409, 'PayPal reconciliation with this idempotency key is already in progress.');
            }

            IdempotencyKey::query()->create([
                'key' => $idempotencyKey,
                'operation' => self::RECONCILE_IDEMPOTENCY_OPERATION,
                'request_hash' => $requestHash,
            ]);

            $target = TenantSubscription::query()->lockForUpdate()->findOrFail($subscription);
            $providerReference = trim((string) $target->encrypted_provider_subscription_id);

            abort_unless(
                strtolower((string) $target->provider) === 'paypal'
                    && $providerReference !== ''
                    && filled($target->provider_subscription_hash),
                409,
                'The subscription is not bound to PayPal.',
            );
            abort_unless(
                hash_equals((string) $target->provider_subscription_hash, hash('sha256', $providerReference)),
                409,
                'PayPal provider binding integrity check failed.',
            );
            abort_if(
                $target->state === SubscriptionState::EXPIRED,
                409,
                'An expired subscription is terminal and cannot be reconciled into a new state.',
            );

            return [
                'provider_reference_hash' => hash('sha256', $providerReference),
            ];
        }, 3);

        if (isset($prepared['replay']) && is_array($prepared['replay'])) {
            return response()->json($prepared['replay']);
        }

        try {
            $providerTarget = TenantSubscription::query()->findOrFail($subscription);
            $snapshot = $providers->for('paypal')->reconcile($providerTarget);

            $result = DB::transaction(function () use (
                $request,
                $subscription,
                $states,
                $reason,
                $idempotencyKey,
                $requestHash,
                $prepared,
                $snapshot,
            ): array {
                $keyRecord = IdempotencyKey::query()
                    ->where('operation', self::RECONCILE_IDEMPOTENCY_OPERATION)
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    hash_equals((string) $keyRecord->request_hash, $requestHash)
                        && ! $keyRecord->completed_at,
                    409,
                    'PayPal reconciliation idempotency state changed before commit.',
                );

                $target = TenantSubscription::query()->lockForUpdate()->findOrFail($subscription);
                $providerReference = trim((string) $target->encrypted_provider_subscription_id);
                abort_unless(
                    strtolower((string) $target->provider) === 'paypal'
                        && $providerReference !== ''
                        && hash_equals((string) $target->provider_subscription_hash, hash('sha256', $providerReference))
                        && hash_equals((string) $prepared['provider_reference_hash'], hash('sha256', $providerReference)),
                    409,
                    'PayPal provider binding changed before reconciliation could be committed.',
                );
                abort_if(
                    $target->state === SubscriptionState::EXPIRED,
                    409,
                    'An expired subscription is terminal and cannot be reconciled into a new state.',
                );

                $returnedReference = trim((string) ($snapshot['provider_subscription_id'] ?? ''));
                abort_unless(
                    $returnedReference !== '' && strcasecmp($returnedReference, $providerReference) === 0,
                    409,
                    'PayPal subscription snapshot reference did not match the selected subscription.',
                );

                $observedAt = $this->providerTimestamp($snapshot['occurred_at'] ?? null, 'provider observation');
                $periodStart = $this->optionalProviderTimestamp($snapshot['current_period_start'] ?? null, 'provider period start');
                $periodEnd = $this->optionalProviderTimestamp($snapshot['current_period_end'] ?? null, 'provider period end');
                abort_if(
                    ($periodStart === null) !== ($periodEnd === null)
                        || ($periodStart && $periodEnd && ! $periodEnd->greaterThan($periodStart)),
                    409,
                    'PayPal subscription snapshot returned an invalid billing period.',
                );

                $lastProviderEvent = $target->last_provider_event_at?->copy()?->utc()?->startOfSecond();
                if ($lastProviderEvent && ! $observedAt->greaterThan($lastProviderEvent)) {
                    $response = [
                        'operation_id' => self::RECONCILE_OPERATION_ID,
                        'mutation' => 'reconciled_stale',
                        'changed' => false,
                        'provider_observed_at' => $observedAt->toIso8601String(),
                        'data' => $this->snapshot($target),
                    ];
                    $this->recordReconciliationAudit(
                        $request,
                        $target,
                        $reason,
                        false,
                        'stale_or_duplicate_provider_snapshot',
                        strtoupper(trim((string) ($snapshot['status'] ?? 'UNKNOWN'))),
                        false,
                        $observedAt,
                    );
                    $keyRecord->response = $response;
                    $keyRecord->completed_at = now();
                    $keyRecord->save();

                    return $response;
                }

                $providerPlanReference = trim((string) ($snapshot['provider_plan_id'] ?? ''));
                $authoritativePlan = null;
                if ($providerPlanReference !== '') {
                    $plans = BillingPlan::query()
                        ->where('provider', 'paypal')
                        ->where('provider_plan_id', $providerPlanReference)
                        ->limit(2)
                        ->get();
                    abort_if($plans->count() === 0, 409, 'PayPal subscription snapshot references an unmapped local plan.');
                    abort_if($plans->count() > 1, 409, 'PayPal plan mapping is ambiguous in the local plan catalog.');
                    $authoritativePlan = $plans->first();
                }

                $providerStatus = strtoupper(trim((string) ($snapshot['status'] ?? '')));
                $targetState = match ($providerStatus) {
                    'SUSPENDED' => SubscriptionState::SUSPENDED,
                    'CANCELLED' => SubscriptionState::CANCELLED,
                    'EXPIRED' => SubscriptionState::EXPIRED,
                    'PAST_DUE' => SubscriptionState::PAST_DUE,
                    'ACTIVE' => $this->providerActiveTarget($target, $periodStart, $lastProviderEvent),
                    default => $target->state,
                };

                if ($targetState !== $target->state) {
                    try {
                        $states->assert($target->state, $targetState);
                    } catch (Throwable) {
                        $targetState = $target->state;
                    }
                }

                $beforeState = $target->state;
                $beforePlanId = (int) $target->billing_plan_id;
                $beforePeriodStart = $target->current_period_start?->copy()?->utc()?->startOfSecond();
                $beforePeriodEnd = $target->current_period_end?->copy()?->utc()?->startOfSecond();

                $stateChanged = $targetState !== $beforeState;
                $planChanged = $authoritativePlan && (int) $authoritativePlan->getKey() !== $beforePlanId;
                $periodChanged = $periodStart && $periodEnd
                    && (
                        ! $beforePeriodStart
                        || ! $beforePeriodEnd
                        || ! $beforePeriodStart->equalTo($periodStart)
                        || ! $beforePeriodEnd->equalTo($periodEnd)
                    );

                $updates = [
                    'state' => $targetState,
                    'last_provider_event_at' => $observedAt,
                ];
                if ($authoritativePlan) {
                    $updates['billing_plan_id'] = (int) $authoritativePlan->getKey();
                    $updates['pending_billing_plan_id'] = null;
                    $updates['plan_change_effective_at'] = null;
                }
                if ($periodStart && $periodEnd) {
                    $updates['current_period_start'] = $periodStart;
                    $updates['current_period_end'] = $periodEnd;
                }
                if ($targetState === SubscriptionState::ACTIVE) {
                    $updates['grace_ends_at'] = null;
                    $updates['cancelled_at'] = null;
                    $updates['ended_at'] = null;
                    $updates['cancel_at_period_end'] = false;
                } elseif ($targetState === SubscriptionState::CANCELLED) {
                    $updates['cancelled_at'] = $observedAt;
                    $updates['ended_at'] = $observedAt;
                } elseif ($targetState === SubscriptionState::EXPIRED) {
                    $updates['ended_at'] = $observedAt;
                }

                $target->forceFill($updates)->save();

                if ($planChanged) {
                    BillingSubscriptionChange::query()
                        ->where('tenant_subscription_id', $target->getKey())
                        ->where('to_billing_plan_id', $authoritativePlan->getKey())
                        ->whereIn('status', ['provider_pending', 'scheduled'])
                        ->latest('id')
                        ->first()
                        ?->forceFill(['status' => 'completed', 'completed_at' => $observedAt])
                        ->save();
                }

                $persisted = TenantSubscription::query()->findOrFail((int) $target->getKey());
                $changed = $stateChanged || $planChanged || $periodChanged;

                $this->recordReconciliationAudit(
                    $request,
                    $persisted,
                    $reason,
                    $changed,
                    'authoritative_provider_snapshot_applied',
                    $providerStatus !== '' ? $providerStatus : 'UNKNOWN',
                    (bool) $planChanged,
                    $observedAt,
                    $beforeState->value,
                );

                $response = [
                    'operation_id' => self::RECONCILE_OPERATION_ID,
                    'mutation' => $changed ? 'reconciled_changed' : 'reconciled_confirmed',
                    'changed' => $changed,
                    'provider_observed_at' => $observedAt->toIso8601String(),
                    'data' => $this->snapshot($persisted),
                ];
                $keyRecord->response = $response;
                $keyRecord->completed_at = now();
                $keyRecord->save();

                return $response;
            }, 3);
        } catch (Throwable $e) {
            IdempotencyKey::query()
                ->where('operation', self::RECONCILE_IDEMPOTENCY_OPERATION)
                ->where('key', $idempotencyKey)
                ->whereNull('completed_at')
                ->delete();
            throw $e;
        }

        $authoritative = TenantSubscription::query()->findOrFail((int) $result['data']['id']);
        abort_unless(
            $authoritative->last_provider_event_at
                && $authoritative->last_provider_event_at->greaterThanOrEqualTo($result['provider_observed_at']),
            409,
            'Persisted billing state did not reconcile after PayPal support reconciliation.',
        );
        $result['data'] = $this->snapshot($authoritative);

        return response()->json($result);
    }

    private function providerActiveTarget(
        TenantSubscription $subscription,
        ?Carbon $periodStart,
        ?Carbon $lastProviderEvent,
    ): SubscriptionState {
        if (in_array($subscription->state, [SubscriptionState::PAST_DUE, SubscriptionState::GRACE], true)) {
            return $periodStart && $lastProviderEvent && $periodStart->greaterThan($lastProviderEvent)
                ? SubscriptionState::ACTIVE
                : $subscription->state;
        }

        return SubscriptionState::ACTIVE;
    }

    private function providerTimestamp(mixed $value, string $label): Carbon
    {
        abort_unless(is_string($value) && trim($value) !== '', 409, "PayPal snapshot is missing {$label}.");

        try {
            return Carbon::parse($value)->utc()->startOfSecond();
        } catch (Throwable) {
            abort(409, "PayPal snapshot returned an invalid {$label}.");
        }
    }

    private function optionalProviderTimestamp(mixed $value, string $label): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->providerTimestamp($value, $label);
    }

    private function recordReconciliationAudit(
        Request $request,
        TenantSubscription $subscription,
        string $reason,
        bool $changed,
        string $result,
        string $providerStatus,
        bool $planChanged,
        Carbon $observedAt,
        ?string $fromState = null,
    ): void {
        BillingAudit::query()->create([
            'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
            'action' => 'billing.support.reconciled',
            'subject_type' => 'subscription',
            'subject_id' => (string) $subscription->getKey(),
            'metadata' => [
                'operation_id' => self::RECONCILE_OPERATION_ID,
                'reason' => $reason,
                'changed' => $changed,
                'result' => $result,
                'provider' => 'paypal',
                'provider_status' => $providerStatus,
                'provider_reference' => $this->maskedProviderReference($subscription->encrypted_provider_subscription_id),
                'provider_observed_at' => $observedAt->toIso8601String(),
                'from_state' => $fromState ?? $subscription->state->value,
                'to_state' => $subscription->state->value,
                'plan_changed' => $planChanged,
                'payment_success_recorded' => false,
                'provider_mutated' => false,
                'request_id' => $request->header('X-Request-Id'),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ],
            'occurred_at' => now(),
        ]);
    }

    private function maskedProviderReference(?string $reference): ?string
    {
        $clean = trim((string) $reference);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) <= 8) {
            return str_repeat('•', mb_strlen($clean));
        }

        return mb_substr($clean, 0, 3).'…'.mb_substr($clean, -4);
    }

    /** @return array<string, mixed> */
    private function snapshot(TenantSubscription $subscription): array
    {
        return [
            'id' => (int) $subscription->getKey(),
            'state' => $subscription->state->value,
            'billing_plan_id' => (int) $subscription->billing_plan_id,
            'provider_bound' => filled($subscription->provider)
                || filled($subscription->provider_subscription_hash)
                || filled($subscription->encrypted_provider_subscription_id),
            'local_access_restored' => $subscription->state === SubscriptionState::ACTIVE,
            'grace_ends_at' => $subscription->grace_ends_at?->utc()->toIso8601String(),
            'payment_success_recorded' => false,
            'updated_at' => $subscription->updated_at?->utc()->toIso8601String(),
        ];
    }
}
