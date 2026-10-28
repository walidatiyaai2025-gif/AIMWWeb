<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SitesBulkDisableSelectedController extends Controller
{
    public const OPERATION_ID = 'AIMW-SYNC-45A3B43BB9';

    public function __invoke(Request $request, string $tenant, TenantAuthorizer $auth, TenantContext $context): JsonResponse
    {
        $auth->authorize('tenant.view');
        $auth->authorize('sites.view');
        $auth->authorize('sites.manage');
        abort_unless(hash_equals($context->tenant()->slug, $tenant), 404);

        if (array_diff(array_keys($request->all()), ['ids'])) {
            throw ValidationException::withMessages(['request' => 'Only selected site IDs are accepted.']);
        }
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_values(array_map('intval', $validated['ids']));
        $tenantId = $context->id();
        $updated = DB::transaction(function () use ($ids, $tenantId): int {
            $sites = Site::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->whereIn('id', $ids)->lockForUpdate()->get();
            abort_unless($sites->count() === count($ids), 404, 'Foreign or missing selected site.');

            $count = 0;
            foreach ($sites as $site) {
                if ((string) $site->connection_status !== 'disabled') {
                    $site->connection_status = 'disabled';
                    $site->save();
                    $count++;
                }
            }

            return $count;
        }, 3);

        // Truthful success requires a fresh tenant-scoped server-side read.
        $verified = Site::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)->get(['id', 'connection_status']);
        abort_unless(
            $verified->count() === count($ids)
                && $verified->every(fn (Site $site) => (string) $site->connection_status === 'disabled'),
            409,
            'Selected site disable could not be verified.',
        );

        return response()->json([
            'operation_id' => self::OPERATION_ID,
            'requested' => count($ids),
            'updated' => $updated,
            'ids' => $ids,
            'connection_status' => 'disabled',
        ]);
    }
}
