<?php

namespace App\Execution;

use App\Models\AuditEvent;
use App\Models\Execution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Laravel adaptation of ExecutionCenterService.FailExternal.
 *
 * The canonical source transitions only the owner's running external execution and
 * records an Error activity. Laravel's `executions` ledger is intrinsically linked
 * to an approved change (`approval_id` is required), so it is the external execution
 * boundary for this adapter. TenantScope supplies the tenant boundary and the actor
 * ID supplies the source owner boundary.
 */
final class ExternalExecutionFailureService
{
    public const OPERATION_ID = 'AIMW-AI-D1F233AB61';

    public const DEFAULT_ERROR = 'Approved change execution failed.';

    /**
     * Fail the active tenant's owned running approved/external execution.
     *
     * A valid but missing, foreign-tenant, wrong-owner, or non-running execution is
     * a fail-closed no-op, matching the source UPDATE predicate semantics.
     */
    public function failExternal(string $operationId, int $ownerUserId, ?string $error): bool
    {
        $operationId = trim($operationId);
        if (! Str::isUuid($operationId)) {
            throw new InvalidArgumentException('A valid execution operation UUID is required.');
        }
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('A valid execution owner user ID is required.');
        }

        $safeError = $this->normalizeError($error);

        return DB::transaction(function () use ($operationId, $ownerUserId, $safeError): bool {
            $execution = Execution::query()
                ->where('operation_id', $operationId)
                ->where('actor_user_id', $ownerUserId)
                ->whereNotNull('approval_id')
                ->where('status', 'running')
                ->lockForUpdate()
                ->first();

            if (! $execution) {
                return false;
            }

            $completedAt = now();
            $execution->forceFill([
                'status' => 'failed',
                'completed_at' => $completedAt,
                'failure' => $safeError,
            ])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $ownerUserId,
                'event' => 'execution.failed',
                'subject_type' => Execution::class,
                'subject_id' => (string) $execution->getKey(),
                'metadata' => [
                    'canonical_operation' => self::OPERATION_ID,
                    'level' => 'Error',
                    'message' => $safeError,
                    'execution_id' => (int) $execution->getKey(),
                    'operation_id' => $operationId,
                    'correlation_id' => $execution->correlation_id,
                    'approval_id' => (int) $execution->approval_id,
                    'site_id' => (int) $execution->site_id,
                ],
                'occurred_at' => $completedAt,
            ]);

            return true;
        });
    }

    private function normalizeError(?string $error): string
    {
        $message = trim((string) $error);
        if ($message === '') {
            return self::DEFAULT_ERROR;
        }

        $clean = preg_replace(
            '/(?i)\b(password|passwd|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token)\b\s*[:=]\s*[^\s,;]+/',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;

        return Str::limit(trim($clean), 1000, '');
    }
}
