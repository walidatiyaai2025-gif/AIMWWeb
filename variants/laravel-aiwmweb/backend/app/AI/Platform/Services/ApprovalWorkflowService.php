<?php

namespace App\AI\Platform\Services;

use App\Authorization\TenantAuthorizer;
use App\Models\Approval;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ApprovalWorkflowService
{
    public const RECORD_EXECUTION_FAILED_OPERATION_ID = 'AIMW-AI-B3BDED59F1';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * Laravel adaptation of canonical ApprovalWorkflowService.RecordExecutionFailed.
     *
     * This transition owns the approval failure state only. The execution lifecycle is
     * deliberately not mutated here; callers that own execution state must reconcile it
     * independently, matching the source service boundary.
     */
    public function recordExecutionFailed(int $approvalId, string $executionId, ?string $error): Approval
    {
        $this->authorizer->authorize('approvals.manage');

        $approval = Approval::query()->find($approvalId);
        if (! $approval) {
            throw (new ModelNotFoundException)->setModel(Approval::class, [$approvalId]);
        }

        $safeError = $this->redactError($error);
        Log::error('Approved change execution failed.', [
            'canonical_operation' => self::RECORD_EXECUTION_FAILED_OPERATION_ID,
            'approval_id' => $approval->getKey(),
            'execution_id' => $executionId,
            'error' => $safeError,
        ]);

        $approval->forceFill(['status' => 'FAILED'])->save();

        return $approval->refresh();
    }

    private function redactError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $clean = preg_replace(
            '/(?i)\b(password|passwd|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token)\b\s*[:=]\s*[^\s,;]+/',
            '$1=[REDACTED]',
            trim($error),
        ) ?? trim($error);

        return Str::limit($clean, 1000, '');
    }
}
