<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\BillingPlan;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class SubscriptionPlansAdminReadController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $tenant): View
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $plans = BillingPlan::query()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get([
                'id',
                'code',
                'name',
                'localized_name',
                'description',
                'localized_description',
                'price_minor',
                'currency',
                'billing_interval',
                'trial_period_days',
                'grace_period_days',
                'enabled',
                'retired_at',
                'display_order',
                'limits',
                'entitlements',
            ]);

        $entitlementForms = $plans
            ->mapWithKeys(fn (BillingPlan $plan): array => [
                $plan->getKey() => SubscriptionPlanEntitlementsController::formState($plan),
            ])
            ->all();

        $cloneForm = null;
        if ($request->query->has('clone')) {
            $cloneRaw = $request->query('clone');
            abort_unless(is_string($cloneRaw) || is_int($cloneRaw), 404);

            $cloneId = filter_var($cloneRaw, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            abort_unless($cloneId !== false, 404);

            $clonePlan = $plans->firstWhere('id', (int) $cloneId);
            abort_unless($clonePlan instanceof BillingPlan, 404);

            $cloneForm = self::cloneForm($clonePlan);
        }

        return view('billing.subscription-plans-admin', [
            'tenant' => $this->context->tenant(),
            'plans' => $plans,
            'entitlementForms' => $entitlementForms,
            'cloneForm' => $cloneForm,
        ]);
    }

    /**
     * @return array{
     *     source_plan_id:int,
     *     code:string,
     *     name_en:string,
     *     name_ar:string,
     *     description_en:string,
     *     description_ar:string,
     *     billing_interval:string,
     *     price:string,
     *     currency:string,
     *     trial_days:int,
     *     grace_period_days:int,
     *     sort_order:int,
     *     is_enabled:string
     * }
     */
    private static function cloneForm(BillingPlan $plan): array
    {
        $nameEn = trim((string) ($plan->localized_name['en'] ?? $plan->name));
        $nameAr = trim((string) ($plan->localized_name['ar'] ?? $plan->name));

        return [
            'source_plan_id' => (int) $plan->getKey(),
            'code' => trim((string) $plan->code).'-copy',
            'name_en' => $nameEn.' Copy',
            'name_ar' => 'نسخة '.$nameAr,
            'description_en' => (string) ($plan->localized_description['en'] ?? $plan->description ?? ''),
            'description_ar' => (string) ($plan->localized_description['ar'] ?? ''),
            'billing_interval' => $plan->billing_interval === 'year' ? 'Yearly' : 'Monthly',
            'price' => number_format(($plan->price_minor ?? 0) / 100, 2, '.', ''),
            'currency' => (string) $plan->currency,
            'trial_days' => (int) $plan->trial_period_days,
            'grace_period_days' => (int) $plan->grace_period_days,
            'sort_order' => (int) $plan->display_order,
            'is_enabled' => '0',
        ];
    }
}
