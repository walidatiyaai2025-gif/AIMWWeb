<?php

namespace App\Billing\Providers;

use App\Models\BillingPlan;
use App\Models\TenantSubscription;
use Illuminate\Http\Request;

interface BillingProvider
{
    public function name(): string;

    public function configured(): bool;

    public function createSubscriptionIntent(TenantSubscription $subscription, BillingPlan $plan): array;

    public function changeSubscription(TenantSubscription $subscription, BillingPlan $plan): array;

    public function cancelSubscription(TenantSubscription $subscription): void;

    public function verifyAndParseWebhook(Request $request): array;

    public function reconcile(TenantSubscription $subscription): array;
}
