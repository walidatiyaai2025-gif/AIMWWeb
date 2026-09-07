<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Execution;
use App\Models\Site;
use App\Services\SeoRemediationClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeoRemediationClosureController extends Controller
{
    public function proposals(int $site, TenantAuthorizer $auth, SeoRemediationClosureService $remediation): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);

        return response()->json(['data' => $remediation->proposals($site)]);
    }

    public function history(int $site, TenantAuthorizer $auth, SeoRemediationClosureService $remediation): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);

        return response()->json(['data' => $remediation->history($site)]);
    }

    public function retryFailed(
        int $site,
        TenantAuthorizer $auth,
        SeoRemediationClosureService $remediation,
    ): JsonResponse {
        $auth->authorize('seo.write');
        $siteModel = Site::query()->findOrFail($site);

        return response()->json($remediation->retryFailed($siteModel), 202);
    }

    public function undo(
        int $site,
        int $execution,
        Request $request,
        TenantAuthorizer $auth,
        SeoRemediationClosureService $remediation,
    ): JsonResponse {
        $auth->authorize('seo.write');
        $siteModel = Site::query()->findOrFail($site);
        $executionModel = Execution::query()->where('site_id', $site)->findOrFail($execution);

        return response()->json(
            $remediation->prepareUndo($siteModel, $executionModel, $request->user()->id),
            201,
        );
    }
}
