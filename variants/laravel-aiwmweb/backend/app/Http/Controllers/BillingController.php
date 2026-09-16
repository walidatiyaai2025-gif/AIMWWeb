<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Billing\EntitlementService;
use App\Billing\Enums\SubscriptionState;
use App\Billing\PermanentSubscriptionCancellationService;
use App\Billing\SubscriptionService;
use App\Billing\UsageQuotaService;
use App\Models\BillingAudit;
use App\Models\BillingPlan;
use App\Models\BillingTransaction;
use App\Models\TenantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BillingController extends Controller
{
    public function plans(): JsonResponse
    {
        return response()->json(['data' => BillingPlan::query()->where('enabled', true)->whereNull('retired_at')->orderBy('display_order')->get()->map(fn ($p) => $this->planResource($p))]);
    }

    public function current(TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('billing.view');
        $s = TenantSubscription::query()->with('plan')->first();

        return response()->json(['data' => $s ? ['state' => $s->state->value, 'plan' => $this->planResource($s->plan), 'started_at' => $s->started_at?->toAtomString(), 'trial_expires_at' => $s->trial_expires_at?->toAtomString(), 'current_period_end' => $s->current_period_end?->toAtomString(), 'grace_ends_at' => $s->grace_ends_at?->toAtomString(), 'cancel_at_period_end' => $s->cancel_at_period_end, 'can_cancel_permanently' => $this->canCancelPermanently($s)] : null]);
    }

    public function trial(TenantAuthorizer $auth, SubscriptionService $service): JsonResponse
    {
        $auth->authorize('billing.manage');

        return response()->json(['data' => $service->startTrial()], 201);
    }

    public function checkout(Request $request, TenantAuthorizer $auth, SubscriptionService $service): JsonResponse
    {
        $auth->authorize('billing.manage');
        $data = $request->validate(['plan_code' => 'required|string|max:64']);
        $plan = BillingPlan::query()->where('code', $data['plan_code'])->firstOrFail();

        return response()->json(['data' => $service->checkout($plan)], 201);
    }

    public function cancel(Request $request, TenantAuthorizer $auth, SubscriptionService $service, PermanentSubscriptionCancellationService $permanent): JsonResponse
    {
        $auth->authorize('billing.manage');
        $data = $request->validate(['mode' => 'nullable|string|in:permanent_provider']);

        if (($data['mode'] ?? null) === 'permanent_provider') {
            $unexpected = array_values(array_diff($request->keys(), ['mode']));
            if ($unexpected !== []) {
                throw ValidationException::withMessages(['request' => 'Permanent cancellation does not accept caller-supplied tenant, user, subscription, or provider identifiers.']);
            }

            $result = $permanent->request();
            $authoritative = TenantSubscription::query()->findOrFail($result['subscription']->id);
            $status = $result['request_status'] === 'provider_accepted' ? 202 : 200;

            return response()->json(['data' => [
                'request_status' => $result['request_status'],
                'state' => $authoritative->state->value,
                'cancel_at_period_end' => $authoritative->cancel_at_period_end,
                'provider_state_authoritative' => true,
            ]], $status);
        }

        $s = $service->cancel();

        return response()->json(['data' => ['state' => $s->state->value, 'cancel_at_period_end' => $s->cancel_at_period_end]]);
    }

    public function changePlan(Request $request, TenantAuthorizer $auth, SubscriptionService $service): JsonResponse
    {
        $auth->authorize('billing.manage');
        $data = $request->validate(['plan_code' => 'required|string|max:64']);
        $plan = BillingPlan::query()->where('code', $data['plan_code'])->firstOrFail();
        $change = $service->requestPlanChange($plan);

        return response()->json(['data' => ['id' => $change->id, 'kind' => $change->kind, 'status' => $change->status, 'effective_at' => $change->effective_at?->toAtomString(), 'blocked_reason' => $change->blocked_reason]], 202);
    }

    public function entitlements(TenantAuthorizer $auth, EntitlementService $service): JsonResponse
    {
        $auth->authorize('billing.view');

        return response()->json(['data' => $service->snapshot()]);
    }

    public function usage(TenantAuthorizer $auth, UsageQuotaService $service): JsonResponse
    {
        $auth->authorize('billing.view');

        return response()->json(['data' => $service->snapshot()]);
    }

    public function history(TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('billing.view');

        return response()->json(['data' => ['audit' => BillingAudit::query()->latest('occurred_at')->limit(100)->get(['action', 'subject_type', 'subject_id', 'metadata', 'occurred_at']), 'transactions' => BillingTransaction::query()->latest('occurred_at')->limit(100)->get(['type', 'status', 'amount_minor', 'currency', 'occurred_at'])]]);
    }

    private function canCancelPermanently(TenantSubscription $subscription): bool
    {
        return $subscription->provider === 'paypal'
            && filled($subscription->encrypted_provider_subscription_id)
            && in_array($subscription->state, [SubscriptionState::ACTIVE, SubscriptionState::PAST_DUE, SubscriptionState::GRACE, SubscriptionState::SUSPENDED], true);
    }

    private function planResource(BillingPlan $p): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'localized_name' => $p->localized_name, 'description' => $p->description, 'price_minor' => $p->price_minor, 'currency' => $p->currency, 'billing_interval' => $p->billing_interval, 'trial_period_days' => $p->trial_period_days, 'grace_period_days' => $p->grace_period_days, 'limits' => $p->limits, 'entitlements' => $p->entitlements, 'checkout_available' => $p->commerciallyConfigured()];
    }
}
