<?php

namespace App\Billing;

use App\Billing\Exceptions\EntitlementDeniedException;
use App\Models\BillingPlan;
use App\Models\TenantSubscription;

final class EntitlementService
{
    public function subscription(): ?TenantSubscription
    {
        return TenantSubscription::query()->with('plan')->first();
    }

    public function plan(): ?BillingPlan
    {
        $s = $this->subscription();
        if (! $s || ! $s->state->grantsEntitlements()) {
            return null;
        } if ($s->state->value === 'TRIALING' && $s->trial_expires_at?->isPast()) {
            return null;
        } if ($s->state->value === 'GRACE' && $s->grace_ends_at?->isPast()) {
            return null;
        }

        return $s->plan;
    }

    public function may(string $capability): bool
    {
        return $this->allows($capability);
    }

    public function allows(string $capability): bool
    {
        $items = $this->plan()?->entitlements ?? [];

        return array_key_exists($capability, $items) ? (bool) $items[$capability] : false;
    }

    public function limit(string $metric): ?int
    {
        $items = $this->plan()?->limits ?? [];
        $value = $items[$metric] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function assert(string $capability): void
    {
        if (! $this->allows($capability)) {
            throw new EntitlementDeniedException("Entitlement denied: {$capability}");
        }
    }

    public function snapshot(): array
    {
        $s = $this->subscription();
        $p = $this->plan();

        return ['state' => $s?->state->value, 'plan' => $p?->code, 'entitlements' => $p?->entitlements ?? [], 'limits' => $p?->limits ?? []];
    }
}
