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

    public const LOAD_OPERATION_ID = 'AIMW-BILL-B26D5099F8';

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

        $execution = $operation->correlation_id !== null
            ? Execution::query()
                ->where('site_id', $operation->site_id)
                ->where('correlation_id', $operation->correlation_id)
                ->first()
            : null;
        $canOpenExecutionCenter = $context->membership()->hasPermission('operations.manage');
        $executionCenterUrl = $execution !== null && $canOpenExecutionCenter
            ? route('canonical.workspace.execution', [
                'tenant' => $tenant,
                'site' => (int) $operation->site_id,
            ])
            : null;

        $siteName = $site?->name ?: 'Site #'.(string) $operation->site_id;
        $details = ! empty($operation->details)
            ? json_encode($operation->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : 'N/A';

        return view('operations.site-operation-details', [
            'operation' => $operation,
            'site' => $site,
            'durationMs' => $durationMs,
            'historyUrl' => route('canonical.workspace.site-operations', ['tenant' => $tenant]),
            'executionCenterUrl' => $executionCenterUrl,
            'operationReport' => [
                'operationId' => (string) $operation->correlation_id,
                'site' => $siteName,
                'siteId' => (string) $operation->site_id,
                'executionJobId' => $execution?->getKey() === null ? null : (string) $execution->getKey(),
                'operation' => (string) $operation->operation,
                'result' => $operation->status === 'succeeded' ? 'Success' : 'Failed',
                'startedUtc' => $operation->started_at?->toIso8601String() ?? 'N/A',
                'completedUtc' => $operation->completed_at?->toIso8601String() ?? 'N/A',
                'durationMs' => $durationMs ?? 0,
                'affectedRecords' => $operation->affected_records === null ? null : (int) $operation->affected_records,
                'message' => (string) ($operation->message ?? ''),
                'details' => $details === false ? 'N/A' : $details,
            ],
        ]);
    }
}
