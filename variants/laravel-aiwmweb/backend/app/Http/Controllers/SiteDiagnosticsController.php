<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Sites\SiteDiagnosticsService;
use App\Sites\SiteEntitlementHook;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteDiagnosticsController extends Controller
{
    public const RETEST_OPERATION_ID = 'AIMW-BILL-E747D0E843';

    public function status(string $tenant, int|string $site, TenantContext $context, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('tenant.view');
        abort_unless($tenant === $context->tenant()->slug, 404);
        $siteId = $this->siteId($site);

        return response()->json($diagnostics->status(Site::query()->findOrFail($siteId)));
    }

    public function recheck(string $tenant, int|string $site, TenantContext $context, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('connector.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        $siteId = $this->siteId($site);

        return response()->json($diagnostics->recheck(Site::query()->findOrFail($siteId)));
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

    public function diagnosticHistory(Request $request, int|string $site, TenantAuthorizer $auth, SiteDiagnosticsService $diagnostics): JsonResponse
    {
        $auth->authorize('tenant.view');
        $siteId = $this->siteId($site);
        $model = Site::query()->findOrFail($siteId);

        // Explicit page/per_page opt in to preserve the legacy bounded items envelope.
        if ($request->exists('page') || $request->exists('per_page')) {
            $params = $request->validate([
                'page' => 'sometimes|integer|min:1|max:1000000',
                'per_page' => 'sometimes|integer|in:10,25,50,100',
            ]);

            return response()->json($diagnostics->paginatedDiagnosticHistory(
                $model,
                (int) ($params['page'] ?? 1),
                (int) ($params['per_page'] ?? 25),
            ));
        }

        // Keep the legacy items-only envelope, but reject malformed limits
        // instead of silently coercing array/string values into a history read.
        $params = $request->validate(['take' => 'sometimes|integer|min:1|max:500']);

        return response()->json(['items' => $diagnostics->diagnosticHistory($model, (int) ($params['take'] ?? 100))]);
    }

    public function operations(Request $request, string $tenant, int|string $site, TenantContext $context, TenantAuthorizer $auth, SiteOperationHistoryService $history): JsonResponse
    {
        $auth->authorize('tenant.view');
        abort_unless($tenant === $context->tenant()->slug, 404);
        $siteId = $this->siteId($site);
        Site::query()->findOrFail($siteId);

        // Preserve old consumers; new clients opt into authoritative filtered pages.
        if ($request->exists('page') || $request->exists('per_page')
            || $request->exists('status') || $request->exists('operation')) {
            $params = $request->validate([
                'page' => 'sometimes|integer|min:1|max:1000000',
                'per_page' => 'sometimes|integer|in:10,25,50,100',
                'status' => 'sometimes|string|in:succeeded,failed',
                'operation' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_\\-]*$/D'],
            ]);

            return response()->json($history->paginateForSite(
                $siteId,
                (int) ($params['page'] ?? 1),
                (int) ($params['per_page'] ?? 25),
                $params['status'] ?? null,
                $params['operation'] ?? null,
            ));
        }

        $params = $request->validate(['take' => 'sometimes|integer|min:1|max:500']);

        return response()->json(['items' => $history->get($siteId, (int) ($params['take'] ?? 100))]);
    }

    private function siteId(int|string $site): int
    {
        abort_unless(is_int($site) || ctype_digit($site), 404);
        $siteId = (int) $site;
        abort_if($siteId < 1, 404);

        return $siteId;
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
