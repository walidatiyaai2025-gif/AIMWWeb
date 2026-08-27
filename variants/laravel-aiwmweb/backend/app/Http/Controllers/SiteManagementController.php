<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Execution;
use App\Models\Site;
use App\Sites\SiteEntitlementHook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SiteManagementController extends Controller
{
    public function index(TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(Site::query()->latest()->get());
    }

    public function store(Request $request, TenantAuthorizer $auth, SiteEntitlementHook $entitlements): JsonResponse
    {
        $auth->authorize('sites.manage');
        try {
            $entitlements->assertCanCreate();
        } catch (RuntimeException $e) {
            return response()->json(['state' => 'CAPABILITY_DISABLED', 'message' => $e->getMessage()], 403);
        }
        $data = $request->validate(['name' => 'required|string|max:255', 'url' => 'required|url|max:2048']);
        $data['url'] = rtrim($data['url'], '/');

        return response()->json(Site::query()->create($data), 201);
    }

    public function show(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(Site::query()->findOrFail($site));
    }

    public function update(Request $request, int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('sites.manage');
        $model = Site::query()->findOrFail($site);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|url|max:2048',
            'status' => 'sometimes|in:active,disabled',
        ]);
        if (isset($data['url'])) {
            $data['url'] = rtrim($data['url'], '/');
        }
        $model->update($data);

        return response()->json($model);
    }

    public function destroy(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('sites.manage');
        $model = Site::query()->findOrFail($site);
        abort_if(Execution::query()->where('site_id', $site)->whereIn('status', ['queued', 'running'])->exists(), 409, 'Active execution prevents deletion.');
        $model->delete();

        return response()->json([], 204);
    }
}
