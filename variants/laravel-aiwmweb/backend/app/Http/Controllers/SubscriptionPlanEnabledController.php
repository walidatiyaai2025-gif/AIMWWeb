<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\BillingPlan;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SubscriptionPlanEnabledController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-812D1C53B6';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $tenant, int $plan): RedirectResponse
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $data = Validator::make($request->all(), [
            'expected_enabled' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'tenant_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'billing_plan_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_product_id' => ['prohibited'],
            'provider_plan_id' => ['prohibited'],
            'retired_at' => ['prohibited'],
        ])->validate();

        $expected = (bool) $data['expected_enabled'];
        $desired = (bool) $data['enabled'];
        if ($expected === $desired) {
            throw ValidationException::withMessages([
                'enabled' => 'The requested plan state must differ from the expected current state.',
            ]);
        }

        $result = DB::transaction(function () use ($request, $plan, $expected, $desired): array {
            $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan);
            $actual = (bool) $persisted->enabled;

            if ($actual !== $expected) {
                // A retry after the first committed request is safe and does not toggle back.
                abort_unless($actual === $desired, 409, 'Subscription plan state changed before this request could be applied.');

                return ['id' => (int) $persisted->getKey(), 'snapshot' => $this->snapshot($persisted)];
            }

            $before = $this->snapshot($persisted);
            $persisted->enabled = $desired;
            $persisted->save();
            $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan);
            $after = $this->snapshot($persisted);

            DB::table('billing_plan_audits')->insert([
                'billing_plan_id' => $persisted->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => $desired ? 'SubscriptionPlan.Enable' : 'SubscriptionPlan.Disable',
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);

            return ['id' => (int) $persisted->getKey(), 'snapshot' => $after];
        }, 3);

        $reread = BillingPlan::query()->findOrFail($result['id']);
        abort_unless($this->snapshot($reread) === $result['snapshot'], 409, 'Persisted subscription plan changed before confirmation.');

        return redirect()
            ->route('tenant.admin.subscription-plans', ['tenant' => $tenant])
            ->with('status', $desired ? 'Plan enabled.' : 'Plan disabled without deleting subscriber data.');
    }

    private function snapshot(BillingPlan $plan): array
    {
        return [
            'id' => (int) $plan->getKey(),
            'code' => $plan->code,
            'enabled' => (bool) $plan->enabled,
            'retired' => $plan->retired_at !== null,
            'provider_bound' => filled($plan->provider_plan_id) || filled($plan->provider_product_id),
        ];
    }
}
