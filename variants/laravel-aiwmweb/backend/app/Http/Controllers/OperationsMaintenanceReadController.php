<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Sites\SiteOperationHistoryService;
use Illuminate\View\View;

final class OperationsMaintenanceReadController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SiteOperationHistoryService $history,
    ) {}

    public function __invoke(string $tenant): View
    {
        $this->authorizer->authorize('execution.view');

        return view('operations-maintenance', [
            'tenant' => $tenant,
            'storage' => $this->history->getStorageInfo(),
            'preview' => $this->history->previewCleanup(90, 100),
        ]);
    }
}
