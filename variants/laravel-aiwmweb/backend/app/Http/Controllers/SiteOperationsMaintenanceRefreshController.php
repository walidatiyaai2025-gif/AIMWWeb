<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class SiteOperationsMaintenanceRefreshController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-C5BC29CF27';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SiteOperationHistoryService $history,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('execution.view');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        return response()->json([
            'data' => [
                'operation_id' => self::OPERATION_ID,
                'storage' => $this->history->getStorageInfo(),
                'preview' => $this->history->previewCleanup(90, 100),
            ],
        ]);
    }
}
