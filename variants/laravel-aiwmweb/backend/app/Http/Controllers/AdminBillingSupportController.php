<?php

namespace App\Http\Controllers;

use App\Billing\Enums\SubscriptionState;
use App\Billing\SubscriptionStateMachine;
use App\Models\BillingAudit;
use App\Models\IdempotencyKey;
use App\Models\TenantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminBillingSupportController extends Controller
{
    public const REACTIVATE_OPERATION_ID = 'AIMW-BILL-29A4267B87';
    public const GRANT_GRACE_OPERATION_ID = 'AIMW-BILL-5A0140C699';

    private const IDEMPOTENCY_OPERATION = 'billing.support.reactivate';
    private const GRANT_GRACE_IDEMPOTENCY_OPERATION = 'billing.support.grant-grace';

    public function index(): JsonResponse
    {
        $subscriptions = TenantSubscription::query()
            ->with('plan:id,code,name')
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (TenantSubscription $subscription): array => [
                ...$this->snapshot($subscription),
                'plan_code' => $subscription->plan?->code,
                'plan_name' => $subscription->plan?->name,
            ])
            ->values();

        return response()->json(['data' => $subscriptions]);
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

            $now = now();
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
                } catch (\Throwable) {
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
        abort_unless(
            $authoritative->state === SubscriptionState::GRACE
                && $authoritative->grace_ends_at
                && $authoritative->grace_ends_at->utc()->toIso8601String() === ($result['data']['grace_ends_at'] ?? null),
            409,
            'Persisted billing state did not reconcile after support grace mutation.',
        );

        $result['data'] = $this->snapshot($authoritative);

        return response()->json($result);
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
