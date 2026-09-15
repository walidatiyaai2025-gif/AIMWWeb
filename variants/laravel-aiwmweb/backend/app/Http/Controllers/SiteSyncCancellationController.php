<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Models\SyncRun;
use App\Sync\SyncCancellationService;
use Illuminate\Http\JsonResponse;

final class SiteSyncCancellationController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-54BB64BB13';

    public function active(
        TenantAuthorizer $auth,
        SyncCancellationService $cancellation,
        string $tenant,
        int $site,
    ): JsonResponse {
        $auth->authorize('content.edit');
        Site::query()->findOrFail($site);

        $run = $cancellation->activeForSite($site);

        return response()->json([
            'active' => $run !== null,
            'run' => $run ? $this->serialize($run) : null,
        ]);
    }

    public function cancel(
        TenantAuthorizer $auth,
        SyncCancellationService $cancellation,
        string $tenant,
        int $site,
    ): JsonResponse {
        $auth->authorize('content.edit');
        Site::query()->findOrFail($site);

        $run = $cancellation->requestForSite($site);
        if (! $run) {
            return response()->json([
                'message' => 'No active synchronization is available to cancel.',
            ], 409);
        }

        return response()->json([
            'operation_id' => self::OPERATION_ID,
            'run' => $this->serialize($run),
        ], 202);
    }

    private function serialize(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'state' => $run->state,
            'requested_at' => $run->requested_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
        ];
    }
}
