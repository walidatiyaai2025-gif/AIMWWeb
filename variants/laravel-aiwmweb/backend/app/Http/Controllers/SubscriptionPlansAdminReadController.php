<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\BillingPlan;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;

final class SubscriptionPlansAdminReadController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(string $tenant): View
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

        return view('billing.subscription-plans-admin', [
            'tenant' => $this->context->tenant(),
            'plans' => $plans,
        ]);
    }
}
