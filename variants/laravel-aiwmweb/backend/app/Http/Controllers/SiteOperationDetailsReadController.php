<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

final class SiteOperationDetailsReadController extends Controller
{
    public function __invoke(
        string $tenant,
        string $operationId,
        SiteOperationHistoryService $history,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): View {
        $authorizer->authorize('execution.view');
        abort_unless($context->tenant()->slug === $tenant, 404);
        abort_unless(Str::isUuid($operationId), 404);

        $operation = $history->getByCorrelationId($operationId);
        abort_if($operation === null, 404);

        $site = Site::query()->find($operation->site_id);
        $durationMs = $operation->started_at && $operation->completed_at
            ? max(0, (int) $operation->started_at->diffInMilliseconds($operation->completed_at))
            : null;

        return view('operations.site-operation-details', [
            'operation' => $operation,
            'site' => $site,
            'durationMs' => $durationMs,
            'historyUrl' => route('canonical.workspace.site-operations', ['tenant' => $tenant]),
        ]);
    }
}
