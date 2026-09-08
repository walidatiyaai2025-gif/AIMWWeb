<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\View\View;

final class OperationsMaintenanceReadController extends Controller
{
    public const OPERATIONS_HUB_OPERATION_ID = 'AIMW-AI-9E73ABE9CE';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SiteOperationHistoryService $history,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(string $tenant): View
    {
        $this->authorizer->authorize('execution.view');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $membership = $this->context->membership();

        return view('operations-maintenance', [
            'tenant' => $this->context->tenant()->slug,
            'storage' => $this->history->getStorageInfo(),
            'preview' => $this->history->previewCleanup(90, 100),
            'canOpenOperationsHub' => $membership->hasPermission('execution.view')
                && $membership->hasPermission('operations.manage'),
        ]);
    }
}
