<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Execution;
use App\Models\Site;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

final class SiteOperationDetailsReadController extends Controller
{
    public const EXECUTION_CENTER_OPERATION_ID = 'AIMW-AI-98F705F888';

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

        $hasExecutionJob = $operation->correlation_id !== null
            && Execution::query()
                ->where('site_id', $operation->site_id)
                ->where('correlation_id', $operation->correlation_id)
                ->exists();
        $canOpenExecutionCenter = $context->membership()->hasPermission('operations.manage');
        $executionCenterUrl = $hasExecutionJob && $canOpenExecutionCenter
            ? route('canonical.workspace.execution', [
                'tenant' => $tenant,
                'site' => (int) $operation->site_id,
            ])
            : null;

        return view('operations.site-operation-details', [
            'operation' => $operation,
            'site' => $site,
            'durationMs' => $durationMs,
            'historyUrl' => route('canonical.workspace.site-operations', ['tenant' => $tenant]),
            'executionCenterUrl' => $executionCenterUrl,
        ]);
    }
}
