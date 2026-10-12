<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Execution;
use App\Models\Site;
use App\Sites\SiteConnectionState;
use App\Sites\SiteDiagnosticsService;
use App\Sites\SiteEntitlementHook;
use App\Sites\SiteManagementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SiteManagementController extends Controller
{
    public const SAVE_OPERATION_ID = 'AIMW-BILL-54E4003BE9';

    public const RETEST_SELECTED_OPERATION_ID = 'AIMW-BILL-DD0FD9926B';

    public function index(TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(Site::query()->latest()->get());
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

    public function retestSelected(
        Request $request,
        string $tenant,
        TenantAuthorizer $auth,
        TenantContext $context,
        SiteDiagnosticsService $diagnostics,
    ): JsonResponse {
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

        $items = [];
        foreach ($ids as $siteId) {
            $site = $sites->get($siteId);

            try {
                $diagnostic = $diagnostics->recheck($site);
                $isSuccess = $diagnostic->classification === SiteConnectionState::CONNECTED;
                $items[] = [
                    'site_id' => $siteId,
                    'site_name' => $site->name,
                    'is_success' => $isSuccess,
                    'message' => $diagnostic->failure_message ?: $diagnostic->classification,
                    'classification' => $diagnostic->classification,
                ];
            } catch (Throwable $e) {
                $items[] = [
                    'site_id' => $siteId,
                    'site_name' => $site->name,
                    'is_success' => false,
                    'message' => $e->getMessage(),
                    'classification' => null,
                ];
            }
        }

        return response()->json([
            'requested' => count($ids),
            'succeeded' => count(array_filter($items, fn (array $item): bool => $item['is_success'])),
            'failed' => count(array_filter($items, fn (array $item): bool => ! $item['is_success'])),
            'items' => $items,
        ]);
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
