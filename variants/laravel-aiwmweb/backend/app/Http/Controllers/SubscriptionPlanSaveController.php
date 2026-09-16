<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\BillingPlan;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SubscriptionPlanSaveController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-5AF09ADABA';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function store(Request $request, string $tenant): RedirectResponse
    {
        $this->authorizeTenant($tenant);
        $data = $this->validated($request, true);
        $attributes = $this->attributes($data, null);

        $result = DB::transaction(function () use ($request, $attributes): array {
            $existing = BillingPlan::query()->where('code', $attributes['code'])->lockForUpdate()->first();
            if ($existing) {
                if (! $this->sameSaveState($existing, $attributes)) {
                    throw ValidationException::withMessages([
                        'code' => 'A subscription plan with this code already exists with different persisted values.',
                    ]);
                }

                return ['id' => (int) $existing->getKey(), 'snapshot' => $this->auditSnapshot($existing)];
            }

            $plan = BillingPlan::query()->create($attributes + [
                'limits' => [],
                'entitlements' => [],
            ]);
            $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $snapshot = $this->auditSnapshot($persisted);
            $this->audit($request, 'SubscriptionPlan.Create', $persisted, null, $snapshot);

            return ['id' => (int) $persisted->getKey(), 'snapshot' => $snapshot];
        }, 3);

        $this->assertAuthoritativeReread($result);

        return redirect()
            ->route('tenant.admin.subscription-plans', ['tenant' => $tenant])
            ->with('status', 'Plan saved.');
    }

    public function update(Request $request, string $tenant, int $plan): RedirectResponse
    {
        $this->authorizeTenant($tenant);
        $data = $this->validated($request, false);

        $result = DB::transaction(function () use ($request, $plan, $data): array {
            $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan);
            $before = $this->auditSnapshot($persisted);
            $persisted->fill($this->attributes($data, $persisted));

            if ($persisted->isDirty()) {
                $persisted->save();
                $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan);
                $after = $this->auditSnapshot($persisted);
                $this->audit($request, 'SubscriptionPlan.Update', $persisted, $before, $after);
            } else {
                $after = $before;
            }

            return ['id' => (int) $persisted->getKey(), 'snapshot' => $after];
        }, 3);

        $this->assertAuthoritativeReread($result);

        return redirect()
            ->route('tenant.admin.subscription-plans', ['tenant' => $tenant])
            ->with('status', 'Plan saved.');
    }

    private function authorizeTenant(string $tenant): void
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);
    }

    private function validated(Request $request, bool $creating): array
    {
        $normalized = [];
        foreach (['name_en', 'name_ar', 'description_en', 'description_ar', 'gateway_product_id', 'gateway_plan_id'] as $key) {
            if ($request->exists($key) && is_string($request->input($key))) {
                $value = trim((string) $request->input($key));
                $normalized[$key] = $value === '' ? null : $value;
            }
        }
        if ($request->exists('currency')) {
            $normalized['currency'] = strtoupper(trim((string) $request->input('currency')));
        }
        if ($creating && $request->exists('code')) {
            $normalized['code'] = strtolower(trim((string) $request->input('code')));
        }
        $request->merge($normalized);

        $rules = [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}$/']
                : ['prohibited'],
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['required', 'string', 'max:160'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'billing_interval' => ['required', Rule::in(['Monthly', 'Yearly'])],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_enabled' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'gateway_product_id' => ['nullable', 'string', 'max:200'],
            'gateway_plan_id' => ['nullable', 'string', 'max:200'],
            'clear_gateway_product_id' => ['sometimes', 'boolean'],
            'clear_gateway_plan_id' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'billing_plan_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'limits' => ['prohibited'],
            'entitlements' => ['prohibited'],
            'retired_at' => ['prohibited'],
        ];

        $validator = Validator::make($request->all(), $rules);

        return $validator->validate();
    }

    private function attributes(array $data, ?BillingPlan $existing): array
    {
        $productId = $existing?->provider_product_id;
        $planId = $existing?->provider_plan_id;

        if (filled($data['gateway_product_id'] ?? null)) {
            $productId = $data['gateway_product_id'];
        } elseif ((bool) ($data['clear_gateway_product_id'] ?? false)) {
            $productId = null;
        }

        if (filled($data['gateway_plan_id'] ?? null)) {
            $planId = $data['gateway_plan_id'];
        } elseif ((bool) ($data['clear_gateway_plan_id'] ?? false)) {
            $planId = null;
        }

        $attributes = [
            'name' => $data['name_en'],
            'localized_name' => ['en' => $data['name_en'], 'ar' => $data['name_ar']],
            'description' => $data['description_en'] ?? null,
            'localized_description' => [
                'en' => $data['description_en'] ?? '',
                'ar' => $data['description_ar'] ?? '',
            ],
            'price_minor' => $this->priceMinor((string) $data['price']),
            'currency' => $data['currency'],
            'billing_interval' => $data['billing_interval'] === 'Yearly' ? 'year' : 'month',
            'trial_period_days' => (int) $data['trial_days'],
            'grace_period_days' => (int) $data['grace_period_days'],
            'enabled' => (bool) $data['is_enabled'],
            'display_order' => (int) $data['sort_order'],
            'provider' => ($productId !== null || $planId !== null) ? 'paypal' : null,
            'provider_product_id' => $productId,
            'provider_plan_id' => $planId,
        ];

        if (isset($data['code'])) {
            $attributes['code'] = $data['code'];
        }

        return $attributes;
    }

    private function priceMinor(string $price): int
    {
        $normalized = number_format((float) $price, 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function sameSaveState(BillingPlan $plan, array $attributes): bool
    {
        foreach ($attributes as $key => $expected) {
            $actual = $plan->getAttribute($key);
            if (in_array($key, ['localized_name', 'localized_description'], true)) {
                if (($actual ?? []) != ($expected ?? [])) {
                    return false;
                }
                continue;
            }
            if ($actual != $expected) {
                return false;
            }
        }

        return true;
    }

    private function auditSnapshot(BillingPlan $plan): array
    {
        return [
            'id' => (int) $plan->getKey(),
            'code' => $plan->code,
            'name' => $plan->name,
            'localized_name' => $plan->localized_name ?? [],
            'description' => $plan->description,
            'localized_description' => $plan->localized_description ?? [],
            'price_minor' => $plan->price_minor,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'trial_period_days' => $plan->trial_period_days,
            'grace_period_days' => $plan->grace_period_days,
            'enabled' => (bool) $plan->enabled,
            'display_order' => $plan->display_order,
            'provider_bound' => filled($plan->provider_plan_id) || filled($plan->provider_product_id),
        ];
    }

    private function audit(Request $request, string $action, BillingPlan $plan, ?array $before, array $after): void
    {
        DB::table('billing_plan_audits')->insert([
            'billing_plan_id' => $plan->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    private function assertAuthoritativeReread(array $result): void
    {
        $reread = BillingPlan::query()->findOrFail($result['id']);
        abort_unless($this->auditSnapshot($reread) === $result['snapshot'], 409, 'Persisted subscription plan changed before confirmation.');
    }
}
