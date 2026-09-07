<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\ContentItem;
use App\Models\Execution;
use App\Models\MediaItem;
use App\Models\Site;
use App\Models\TenantMembership;
use App\Platform\BuildInformationReadService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class PlatformReadController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
        private readonly BuildInformationReadService $buildInformation,
    ) {}

    public function build(Request $request): JsonResponse
    {
        return $this->withinSelectedTenant($request, function (): JsonResponse {
            $this->authorizer->authorize('execution.view');

            return response()->json($this->buildInformation->snapshot());
        });
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->withinSelectedTenant($request, function (): JsonResponse {
            $this->authorizer->authorize('execution.view');

            $sites = Site::query()->get([
                'id', 'name', 'connection_status', 'health_state', 'last_verified_at', 'last_sync_at',
            ]);
            $totalSites = $sites->count();
            $connectedSites = $sites->where('connection_status', 'connected')->count();
            $problemSites = $sites->whereIn('connection_status', [
                'unreachable', 'authentication_failed', 'limited_permissions', 'degraded', 'error',
            ])->count();

            $posts = ContentItem::query()->where('type', 'post')->count();
            $pages = ContentItem::query()->where('type', 'page')->count();
            $media = MediaItem::query()->count();

            $activeJobs = Execution::query()->whereIn('status', ['queued', 'running', 'waiting', 'paused'])->count();
            $completedJobs = Execution::query()->whereIn('status', ['completed', 'succeeded'])->count();
            $failedJobs = Execution::query()->where('status', 'failed')->count();

            $recentJobs = Execution::query()->orderByDesc('id')->limit(5)->get([
                'id', 'operation_id', 'site_id', 'status', 'attempts', 'started_at', 'completed_at', 'failure', 'created_at',
            ])->map(static fn (Execution $execution): array => [
                'id' => $execution->id,
                'operationId' => $execution->operation_id,
                'siteId' => $execution->site_id,
                'status' => $execution->status,
                'attempts' => $execution->attempts,
                'createdAtUtc' => $execution->created_at?->toIso8601String(),
                'startedAtUtc' => $execution->started_at?->toIso8601String(),
                'completedAtUtc' => $execution->completed_at?->toIso8601String(),
                'error' => $execution->failure,
            ])->values()->all();

            $lastSync = collect([
                $sites->max('last_sync_at'),
                ContentItem::query()->max('synced_at'),
                MediaItem::query()->max('synced_at'),
            ])->filter()->map(static fn ($value) => Carbon::parse($value))->sortDesc()->first();

            $lastConnectionTest = $sites->pluck('last_verified_at')
                ->filter()
                ->map(static fn ($value) => Carbon::parse($value))
                ->sortDesc()
                ->first();

            return response()->json([
                'sites' => [
                    'totalSites' => $totalSites,
                    'connectedSites' => $connectedSites,
                    'problemSites' => $problemSites,
                    'lastConnectionTestAtUtc' => $lastConnectionTest?->toIso8601String(),
                ],
                'posts' => $posts,
                'pages' => $pages,
                'media' => $media,
                'activeJobs' => $activeJobs,
                'completedJobs' => $completedJobs,
                'failedJobs' => $failedJobs,
                'healthScore' => $this->healthScore($totalSites, $connectedSites, $failedJobs),
                'lastSynchronizationAtUtc' => $lastSync?->toIso8601String(),
                'recentJobs' => $recentJobs,
                'generatedAtUtc' => now()->utc()->toIso8601String(),
            ]);
        });
    }

    private function withinSelectedTenant(Request $request, Closure $callback): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $memberships = TenantMembership::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->with('tenant')
            ->get();

        $requestedSlug = trim((string) $request->query('tenant', ''));
        if ($requestedSlug !== '') {
            $membership = $memberships->first(
                static fn (TenantMembership $candidate): bool => $candidate->tenant?->slug === $requestedSlug,
            );
            abort_unless($membership?->tenant, 404);
        } else {
            if ($memberships->count() !== 1) {
                return response()->json([
                    'message' => 'An explicit tenant selector is required for this canonical API.',
                    'code' => 'TENANT_SELECTION_REQUIRED',
                ], 409);
            }
            $membership = $memberships->first();
        }

        $this->context->activate($membership->tenant, $membership);
        $request->attributes->set('tenant_id', (int) $membership->tenant->getKey());

        try {
            return $callback();
        } finally {
            $this->context->forget();
        }
    }

    private function healthScore(int $totalSites, int $connectedSites, int $failedJobs): int
    {
        if ($totalSites === 0) {
            return $failedJobs === 0 ? 100 : 80;
        }

        $connectionScore = ($connectedSites * 100) / $totalSites;
        $failurePenalty = min(30, $failedJobs * 5);

        return max(0, min(100, (int) round($connectionScore - $failurePenalty)));
    }
}
