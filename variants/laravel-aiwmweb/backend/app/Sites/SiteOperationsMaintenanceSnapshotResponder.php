<?php

namespace App\Sites;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SiteOperationsMaintenanceSnapshotResponder
{
    private const ALLOWED_OLDER_THAN_DAYS = [30, 60, 90, 180, 365];

    private const ALLOWED_KEEP_LATEST = [50, 100, 250, 500];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SiteOperationHistoryService $history,
        private readonly TenantContext $context,
    ) {}

    public function respond(Request $request, string $tenant, string $operationId): JsonResponse
    {
        $this->authorizer->authorize('execution.view');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $validated = $request->validate([
            'older_than_days' => ['sometimes', 'integer', Rule::in(self::ALLOWED_OLDER_THAN_DAYS)],
            'keep_latest' => ['sometimes', 'integer', Rule::in(self::ALLOWED_KEEP_LATEST)],
        ]);
        $olderThanDays = (int) ($validated['older_than_days'] ?? 90);
        $keepLatest = (int) ($validated['keep_latest'] ?? 100);

        return response()->json([
            'data' => [
                'operation_id' => $operationId,
                'policy' => [
                    'older_than_days' => $olderThanDays,
                    'keep_latest' => $keepLatest,
                ],
                'storage' => $this->history->getStorageInfo(),
                'preview' => $this->history->previewCleanup($olderThanDays, $keepLatest),
            ],
        ]);
    }
}
