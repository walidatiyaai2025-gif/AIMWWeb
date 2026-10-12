<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Billing\EntitlementService;
use App\Billing\UsageQuotaService;
use App\Models\Site;
use App\Models\TenantSubscription;
use App\Operations\OperationsControlPlaneService;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class RouteApiAdapterController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly OperationsControlPlaneService $operations,
        private readonly SiteOperationHistoryService $siteOperations,
        private readonly EntitlementService $entitlements,
        private readonly UsageQuotaService $usage,
        private readonly TenantContext $context,
    ) {}

    public function reportExports(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('reports.view');

        return response()->json([
            'data' => $this->operations->operations(['type' => 'report.']),
        ]);
    }

    public function siteOperations(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('execution.view');

        return response()->json([
            'data' => $this->siteOperations->getAll(2000),
        ]);
    }

    public function siteReliability(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('sites.view');

        $siteNames = Site::query()->pluck('name', 'id');
        $rows = collect($this->siteOperations->getAll(2000))
            ->filter(fn (array $item): bool => str_contains(strtolower((string) ($item['operation'] ?? '')), 'connection')
                || str_contains(strtolower((string) ($item['operation'] ?? '')), 'synchron')
                || str_contains(strtolower((string) ($item['operation'] ?? '')), 'sync'))
            ->filter(fn (array $item): bool => ($item['started_at'] ?? null) !== null
                && CarbonImmutable::parse($item['started_at'])->greaterThanOrEqualTo(now()->subDays(30)))
            ->groupBy('site_id')
            ->map(function ($items, $siteId) use ($siteNames): array {
                $total = $items->count();
                $successful = $items->where('status', 'succeeded')->count();
                $failed = $total - $successful;
                $lastFailure = $items
                    ->reject(fn (array $item): bool => ($item['status'] ?? null) === 'succeeded')
                    ->sortByDesc('started_at')
                    ->first();

                return [
                    'site_id' => (int) $siteId,
                    'site_name' => (string) ($siteNames[(int) $siteId] ?? ('Site '.(int) $siteId)),
                    'total_operations' => $total,
                    'successful' => $successful,
                    'failed' => $failed,
                    'success_rate' => $total === 0 ? 0 : round(($successful * 100) / $total, 1),
                    'last_failure_at' => $lastFailure['started_at'] ?? null,
                    'retained_history_only' => true,
                ];
            })
            ->sortBy([
                ['success_rate', 'asc'],
                ['total_operations', 'desc'],
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'period_days' => 30,
                'retained_history_limit' => 2000,
                'history_may_be_truncated' => count($this->siteOperations->getAll(2000)) >= 2000,
                'live_health_monitor' => false,
            ],
        ]);
    }

    public function billingOverview(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('billing.view');
        $subscription = TenantSubscription::query()->with('plan')->first();

        return response()->json([
            'data' => [
                [
                    'section' => 'subscription',
                    'state' => $subscription?->state?->value,
                    'plan_code' => $subscription?->plan?->code,
                    'plan_name' => $subscription?->plan?->name,
                    'trial_expires_at' => $subscription?->trial_expires_at?->toIso8601String(),
                    'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
                    'grace_ends_at' => $subscription?->grace_ends_at?->toIso8601String(),
                    'cancel_at_period_end' => $subscription?->cancel_at_period_end ?? false,
                ],
                [
                    'section' => 'entitlements',
                    'snapshot' => $this->entitlements->snapshot(),
                ],
                [
                    'section' => 'usage',
                    'snapshot' => $this->usage->snapshot(),
                ],
            ],
        ]);
    }

    public function accountProfile(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        $membership = $this->context->membership()->loadMissing(['user:id,name,email', 'roles:id,name']);

        return response()->json([
            'data' => [[
                'user_id' => $membership->user_id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'membership_status' => $membership->status,
                'roles' => $membership->roles->pluck('name')->values()->all(),
            ]],
        ]);
    }
}
