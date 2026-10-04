<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Models\SyncedContent;
use App\Models\SyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class SiteDataSnapshotController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-2D6F2BC88E';

    public function __invoke(
        string $tenant,
        int|string $site,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): JsonResponse {
        $authorizer->authorize('tenant.view');
        $authorizer->authorize('sites.view');
        abort_unless($context->tenant()->slug === $tenant, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);

        $siteId = (int) $site;
        abort_if($siteId < 1, 404);

        $model = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereKey($siteId)
            ->firstOrFail();

        $counts = SyncedContent::query()
            ->where('site_id', $siteId)
            ->select('resource_type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('resource_type')
            ->pluck('aggregate', 'resource_type')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $latestRun = SyncRun::query()
            ->where('site_id', $siteId)
            ->latest('id')
            ->first();

        return response()->json([
            'operation_id' => self::OPERATION_ID,
            'site' => [
                'id' => (int) $model->getKey(),
                'name' => (string) $model->name,
                'connection_status' => (string) $model->connection_status,
                'last_sync_at' => $model->last_sync_at?->utc()->toIso8601String(),
            ],
            'cached' => [
                'total' => array_sum($counts),
                'by_type' => $counts,
            ],
            'latest_run' => $latestRun ? [
                'id' => (int) $latestRun->getKey(),
                'status' => (string) $latestRun->status,
                'processed' => (int) ($latestRun->processed ?? 0),
                'failure' => $latestRun->failure,
                'completed_at' => $latestRun->completed_at?->utc()->toIso8601String(),
            ] : null,
        ]);
    }
}
