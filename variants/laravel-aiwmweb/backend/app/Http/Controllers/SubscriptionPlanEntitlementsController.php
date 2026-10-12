<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\BillingPlan;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SubscriptionPlanEntitlementsController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-887504EDDD';

    /** @var array<string, string> */
    private const INTEGER_FIELDS = [
        'sites_max' => 'sites.max',
        'email_site_recipients_max' => 'email.siteRecipients.max',
        'email_schedules_max' => 'email.schedules.max',
        'automation_schedules_max' => 'automation.schedules.max',
        'ai_monthly_requests_max' => 'ai.monthlyRequests.max',
        'backup_retention_days' => 'backup.retentionDays',
    ];

    /** @var array<string, string> */
    private const BOOLEAN_FIELDS = [
        'email_dashboard_digest' => 'email.dashboardDigest',
        'ai_enabled' => 'ai.enabled',
        'premium_seo' => 'premium.seo',
    ];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {
    }

    public function __invoke(Request $request, string $tenant, int $plan): RedirectResponse
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $data = $this->validated($request);

        $result = DB::transaction(function () use ($request, $plan, $data): array {
            $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan);
            $beforeState = self::managedState($persisted);
            abort_unless(
                hash_equals(self::stateToken($beforeState), (string) $data['expected_state']),
                409,
                'Subscription plan limits changed before this save. Reload and retry.'
            );

            $limits = $persisted->limits ?? [];
            $entitlements = $persisted->entitlements ?? [];

            foreach (self::INTEGER_FIELDS as $field => $key) {
                $limits[$key] = (int) $data[$field];
            }
            foreach (self::BOOLEAN_FIELDS as $field => $key) {
                $entitlements[$key] = (bool) $data[$field];
            }

            $afterState = [
                'limits' => self::orderedManaged($limits, self::INTEGER_FIELDS),
                'entitlements' => self::orderedManaged($entitlements, self::BOOLEAN_FIELDS),
            ];

            $missingManagedKey = collect(array_values(self::INTEGER_FIELDS))
                ->contains(fn (string $key): bool => array_key_exists($key, $persisted->limits ?? []) === false)
                || collect(array_values(self::BOOLEAN_FIELDS))
                    ->contains(fn (string $key): bool => array_key_exists($key, $persisted->entitlements ?? []) === false);

            if ($beforeState !== $afterState || $missingManagedKey) {
                $persisted->limits = $limits;
                $persisted->entitlements = $entitlements;
                $persisted->save();

                $persisted = BillingPlan::query()->lockForUpdate()->findOrFail($plan);
                $authoritativeState = self::managedState($persisted);
                abort_unless($authoritativeState === $afterState, 409, 'Persisted subscription limits changed before confirmation.');

                DB::table('billing_plan_audits')->insert([
                    'billing_plan_id' => $persisted->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'SubscriptionPlan.UpdateEntitlements',
                    'before' => json_encode($beforeState, JSON_THROW_ON_ERROR),
                    'after' => json_encode($authoritativeState, JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                ]);
            }

            return ['id' => (int) $persisted->getKey(), 'state' => self::managedState($persisted)];
        }, 3);

        $reread = BillingPlan::query()->findOrFail($result['id']);
        abort_unless(self::managedState($reread) === $result['state'], 409, 'Persisted subscription limits changed before confirmation.');

        return redirect()
            ->route('tenant.admin.subscription-plans', ['tenant' => $tenant])
            ->with('status', 'Plan limits and capabilities saved.');
    }

    /**
     * @return array{limits: array<string, int>, entitlements: array<string, bool>}
     */
    public static function managedState(BillingPlan $plan): array
    {
        return [
            'limits' => self::orderedManaged($plan->limits ?? [], self::INTEGER_FIELDS),
            'entitlements' => self::orderedManaged($plan->entitlements ?? [], self::BOOLEAN_FIELDS),
        ];
    }

    /**
     * @return array<string, int|bool|string>
     */
    public static function formState(BillingPlan $plan): array
    {
        $state = self::managedState($plan);

        return [
            'expected_state' => self::stateToken($state),
            'sites_max' => $state['limits']['sites.max'],
            'email_site_recipients_max' => $state['limits']['email.siteRecipients.max'],
            'email_schedules_max' => $state['limits']['email.schedules.max'],
            'email_dashboard_digest' => $state['entitlements']['email.dashboardDigest'],
            'automation_schedules_max' => $state['limits']['automation.schedules.max'],
            'ai_enabled' => $state['entitlements']['ai.enabled'],
            'ai_monthly_requests_max' => $state['limits']['ai.monthlyRequests.max'],
            'backup_retention_days' => $state['limits']['backup.retentionDays'],
            'premium_seo' => $state['entitlements']['premium.seo'],
        ];
    }

    /**
     * @param array{limits: array<string, int>, entitlements: array<string, bool>} $state
     */
    public static function stateToken(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $fieldMap
     * @return array<string, int|bool>
     */
    private static function orderedManaged(array $source, array $fieldMap): array
    {
        $result = [];
        foreach ($fieldMap as $key) {
            if (in_array($key, self::INTEGER_FIELDS, true)) {
                $result[$key] = max(0, (int) ($source[$key] ?? 0));
            } else {
                $result[$key] = (bool) ($source[$key] ?? false);
            }
        }

        return $result;
    }

    private function validated(Request $request): array
    {
        $rules = [
            'expected_state' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'sites_max' => ['required', 'integer', 'min:0', 'max:999999999'],
            'email_site_recipients_max' => ['required', 'integer', 'min:0', 'max:999999999'],
            'email_schedules_max' => ['required', 'integer', 'min:0', 'max:999999999'],
            'automation_schedules_max' => ['required', 'integer', 'min:0', 'max:999999999'],
            'ai_monthly_requests_max' => ['required', 'integer', 'min:0', 'max:999999999'],
            'backup_retention_days' => ['required', 'integer', 'min:0', 'max:999999999'],
            'email_dashboard_digest' => ['required', 'boolean'],
            'ai_enabled' => ['required', 'boolean'],
            'premium_seo' => ['required', 'boolean'],
            'tenant_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'billing_plan_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_product_id' => ['prohibited'],
            'provider_plan_id' => ['prohibited'],
            'limits' => ['prohibited'],
            'entitlements' => ['prohibited'],
        ];

        return Validator::make($request->all(), $rules)->validate();
    }
}
