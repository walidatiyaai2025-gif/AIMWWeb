<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SitesBulkOperationalStateController extends Controller
{
    public const ENABLE_OPERATION_ID = 'AIMW-SYNC-38D8A24B15';

    public function enable(Request $request, string $tenant, TenantAuthorizer $auth, TenantContext $context): JsonResponse
    {
        return $this->setSelectedDisabled($request, $tenant, $auth, $context, false);
    }

    private function setSelectedDisabled(
        Request $request,
        string $tenant,
        TenantAuthorizer $auth,
        TenantContext $context,
        bool $disabled,
    ): JsonResponse {
        $auth->authorize('tenant.view');
        $auth->authorize('sites.view');
        $auth->authorize('sites.manage');
        abort_unless(hash_equals($context->tenant()->slug, $tenant), 404);

        // Caller cannot set tenant, actor, site state, or any other persisted field.
        if (array_diff(array_keys($request->all()), ['ids'])) {
            throw ValidationException::withMessages([
                'request' => 'Only selected site IDs may be submitted; identity and state are server-derived.',
            ]);
        }
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_values(array_map('intval', $validated['ids']));
        $target = $disabled ? 'disabled' : 'unknown';
        $tenantId = $context->id();

        $changed = DB::transaction(function () use ($tenantId, $ids, $target): int {
            $sites = Site::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();
            abort_unless($sites->count() === count($ids), 404, 'Selected site does not belong to the active tenant.');

            $count = 0;
            foreach ($sites as $site) {
                if ((string) $site->connection_status !== $target) {
                    $site->connection_status = $target;
                    $site->save();
                    $count++;
                }
            }

            return $count;
        }, 3);

        $authoritative = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'connection_status']);
        abort_unless(
            $authoritative->count() === count($ids)
                && $authoritative->every(fn (Site $site): bool => (string) $site->connection_status === $target),
            409,
            'Selected site operational state could not be verified after persistence.',
        );

        return response()->json([
            'operation_id' => self::ENABLE_OPERATION_ID,
            'requested' => count($ids),
            'updated' => $changed,
            'connection_status' => $target,
            'ids' => $ids,
        ]);
    }
}
