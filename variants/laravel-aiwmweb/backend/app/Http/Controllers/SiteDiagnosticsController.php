<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Sites\SiteDiagnosticsService;
use App\Sites\SiteEntitlementHook;
use App\Sites\SiteOperationHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteDiagnosticsController extends Controller
{
    public function status(int $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json($diagnostics->status(Site::query()->findOrFail($site)));
    }

    public function recheck(int $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('connector.manage');

        return response()->json($diagnostics->recheck(Site::query()->findOrFail($site)));
    }

    public function reconnect(int $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('connector.manage');

        return response()->json($diagnostics->reconnect(Site::query()->findOrFail($site)), 202);
    }

    public function disconnect(int $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('connector.manage');

        return response()->json($diagnostics->disconnect(Site::query()->findOrFail($site)));
    }

    public function capabilities(int $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json($diagnostics->capabilities(Site::query()->findOrFail($site)));
    }

    public function diagnosticHistory(Request $request, int $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('tenant.view');
        $model = Site::query()->findOrFail($site);

        return response()->json(['items' => $diagnostics->diagnosticHistory($model, $request->integer('take', 100))]);
    }

    public function operations(Request $request, int $site, TenantAuthorizer $auth, SiteOperationHistoryService $history): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);

        return response()->json(['items' => $history->get($site, $request->integer('take', 100))]);
    }

    public function operationSummary(TenantAuthorizer $auth, SiteOperationHistoryService $history): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json($history->getSummary());
    }

    public function storage(TenantAuthorizer $auth, SiteOperationHistoryService $history): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json($history->getStorageInfo());
    }

    public function previewCleanup(Request $request, TenantAuthorizer $auth, SiteOperationHistoryService $history): JsonResponse
    {
        $auth->authorize('sites.manage');
        $data = $request->validate(['older_than_days' => 'required|integer|min:1|max:3650', 'keep_latest' => 'sometimes|integer|min:0|max:2000']);

        return response()->json($history->previewCleanup($data['older_than_days'], $data['keep_latest'] ?? 100));
    }

    public function cleanup(Request $request, TenantAuthorizer $auth, SiteOperationHistoryService $history): JsonResponse
    {
        $auth->authorize('sites.manage');
        $data = $request->validate(['older_than_days' => 'required|integer|min:1|max:3650', 'keep_latest' => 'sometimes|integer|min:0|max:2000']);

        return response()->json($history->cleanup($data['older_than_days'], $data['keep_latest'] ?? 100));
    }

    public function entitlements(TenantAuthorizer $auth, SiteEntitlementHook $entitlements): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json($entitlements->snapshot());
    }
}
