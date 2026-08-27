<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Billing\EntitlementService;
use App\Billing\SubscriptionService;
use App\Billing\UsageQuotaService;
use App\Models\BillingAudit;
use App\Models\BillingPlan;
use App\Models\BillingTransaction;
use App\Models\TenantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json(['data' => $s ? ['state' => $s->state->value, 'plan' => $this->planResource($s->plan), 'started_at' => $s->started_at?->toAtomString(), 'trial_expires_at' => $s->trial_expires_at?->toAtomString(), 'current_period_end' => $s->current_period_end?->toAtomString(), 'grace_ends_at' => $s->grace_ends_at?->toAtomString(), 'cancel_at_period_end' => $s->cancel_at_period_end] : null]);
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

    public function cancel(TenantAuthorizer $auth, SubscriptionService $service): JsonResponse
    {
        $auth->authorize('billing.manage');
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

    private function planResource(BillingPlan $p): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'localized_name' => $p->localized_name, 'description' => $p->description, 'price_minor' => $p->price_minor, 'currency' => $p->currency, 'billing_interval' => $p->billing_interval, 'trial_period_days' => $p->trial_period_days, 'grace_period_days' => $p->grace_period_days, 'limits' => $p->limits, 'entitlements' => $p->entitlements, 'checkout_available' => $p->commerciallyConfigured()];
    }
}
