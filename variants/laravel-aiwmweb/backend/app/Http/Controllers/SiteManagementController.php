<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Execution;
use App\Models\Site;
use App\Sites\SiteEntitlementHook;
use App\Sites\SiteManagementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SiteManagementController extends Controller
{
    public const SAVE_OPERATION_ID = 'AIMW-BILL-54E4003BE9';

    public function index(Request $request, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        // Preserve the original array response for site pickers and onboarding.
        // Only explicit paging requests receive authoritative tenant-scoped totals.
        if (! $request->hasAny(['page', 'per_page', 'connection_filter'])) {
            return response()->json(Site::query()->orderByDesc('created_at')->orderByDesc('id')->get());
        }

        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1|max:100000',
            'per_page' => 'sometimes|integer|in:10,25,50,100',
            'connection_filter' => 'sometimes|in:all,connected,review',
            'search' => 'sometimes|nullable|string|max:150',
        ]);
        $pageSize = (int) ($validated['per_page'] ?? 25);
        $requestedPage = (int) ($validated['page'] ?? 1);
        $filter = $validated['connection_filter'] ?? 'all';

        // Site::query() owns the tenant global scope for records and facets.
        $tenantSites = Site::query();
        $connectedCount = (clone $tenantSites)->where('connection_status', 'connected')->count();
        $reviewCount = (clone $tenantSites)
            ->whereNotNull('connection_status')
            ->whereNotIn('connection_status', ['connected', 'unknown', ''])
            ->count();

        $query = Site::query();
        if ($filter === 'connected') {
            $query->where('connection_status', 'connected');
        } elseif ($filter === 'review') {
            $query->whereNotNull('connection_status')
                ->whereNotIn('connection_status', ['connected', 'unknown', '']);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('url', 'like', '%'.$search.'%');
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $currentPage = min($requestedPage, $lastPage);
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($currentPage, $pageSize)->get();

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $pageSize,
            'meta' => [
                'connected_count' => $connectedCount,
                'review_count' => $reviewCount,
            ],
        ]);
    }

    public function store(
        Request $request,
        string $tenant,
        TenantAuthorizer $auth,
        SiteEntitlementHook $entitlements,
        TenantContext $context,
    ): JsonResponse {
        $auth->authorize('sites.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        try {
            $entitlements->assertCanCreate();
        } catch (RuntimeException $e) {
            return response()->json(['state' => 'CAPABILITY_DISABLED', 'message' => $e->getMessage()], 403);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'id' => 'prohibited',
            'tenant_id' => 'prohibited',
            'status' => 'prohibited',
        ]);
        $data['url'] = rtrim($data['url'], '/');

        return response()->json(Site::query()->create($data), 201);
    }

    public function show(
        string $tenant,
        int|string $site,
        TenantAuthorizer $auth,
        TenantContext $context,
        SiteManagementService $sites,
    ): JsonResponse {
        $auth->authorize('tenant.view');
        $auth->authorize('sites.view');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);

        $siteId = (int) $site;
        abort_if($siteId < 1, 404);

        $details = $sites->getDetails($siteId);
        abort_if($details === null, 404);

        return response()->json($details);
    }

    public function update(
        Request $request,
        string $tenant,
        int|string $site,
        TenantAuthorizer $auth,
        TenantContext $context,
    ): JsonResponse {
        $auth->authorize('sites.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);
        $siteId = (int) $site;
        abort_if($siteId < 1, 404);

        $model = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereKey($siteId)
            ->firstOrFail();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'id' => 'prohibited',
            'tenant_id' => 'prohibited',
            'status' => 'prohibited',
        ]);
        $data['url'] = rtrim($data['url'], '/');
        $model->update($data);

        return response()->json($model->fresh());
    }

    public function destroy(
        string $tenant,
        int|string $site,
        TenantAuthorizer $auth,
        TenantContext $context,
    ): JsonResponse {
        $auth->authorize('sites.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);

        $siteId = (int) $site;
        abort_if($siteId < 1, 404);

        $model = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereKey($siteId)
            ->firstOrFail();

        $activeExecutionExists = Execution::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->where('site_id', $siteId)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        abort_if($activeExecutionExists, 409, 'Active execution prevents deletion.');

        DB::transaction(function () use ($model): void {
            $model->delete();
        });

        $stillExists = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereKey($siteId)
            ->exists();
        abort_if($stillExists, 409, 'Site deletion could not be verified.');

        return response()->json([], 204);
    }

    public function bulkDestroy(Request $request, string $tenant, TenantAuthorizer $auth, TenantContext $context): JsonResponse
    {
        $auth->authorize('sites.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_values(array_map('intval', $data['ids']));

        $sites = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        abort_unless($sites->count() === count($ids), 404, 'One or more selected sites do not belong to the active tenant.');

        $activeExecutionExists = Execution::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereIn('site_id', $ids)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        abort_if($activeExecutionExists, 409, 'Active execution prevents bulk deletion.');

        DB::transaction(function () use ($sites): void {
            foreach ($sites as $site) {
                $site->delete();
            }
        });

        return response()->json(['deleted' => count($ids), 'ids' => $ids]);
    }
}
