<?php

namespace App\Execution;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Laravel adaptation of the canonical ExecutionCenterService.GetJobs operation.
 *
 * AIMWWeb keeps tracked and external work in one execution-center ledger. The Laravel
 * variant already has two authoritative stores: `operation_executions` for control-plane
 * jobs and `executions` for approval-backed external work. This adapter composes those
 * existing stores instead of creating a competing execution ledger.
 */
final class ExecutionCenterServiceAdapter
{
    public const OPERATION_ID = 'AIMW-AUTO-035FFB3624';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Return the active tenant's jobs owned by one user, newest first.
     *
     * The source also has an unscoped GetJobs() overload. The multi-tenant Laravel
     * boundary intentionally exposes only the owner-scoped form so tenant or user
     * identifiers can never broaden this read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getJobs(int $ownerUserId): array
    {
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('A valid execution owner user ID is required.');
        }

        $tenantId = $this->context->id();

        $controlPlane = DB::table('operation_executions')
            ->where('tenant_id', $tenantId)
            ->where('requested_by_user_id', $ownerUserId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id', 'requested_by_user_id', 'type', 'subject_type', 'subject_id',
                'correlation_id', 'status', 'progress', 'attempts', 'max_attempts',
                'safe_to_cancel', 'failure', 'started_at', 'completed_at', 'created_at',
            ])
            ->map(fn (object $row): array => [
                'job_id' => (string) $row->correlation_id,
                'ledger' => 'operation_execution',
                'row_id' => (int) $row->id,
                'owner_user_id' => (int) $row->requested_by_user_id,
                'site_id' => null,
                'type' => (string) $row->type,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'status' => (string) $row->status,
                'progress' => (int) $row->progress,
                'attempts' => (int) $row->attempts,
                'max_attempts' => (int) $row->max_attempts,
                'safe_to_cancel' => (bool) $row->safe_to_cancel,
                'created_at' => (string) $row->created_at,
                'started_at' => $row->started_at === null ? null : (string) $row->started_at,
                'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
                'error' => $this->safeFailure($row->failure),
            ]);

        $approvalBacked = DB::table('executions')
            ->where('tenant_id', $tenantId)
            ->where('actor_user_id', $ownerUserId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id', 'operation_id', 'correlation_id', 'site_id', 'actor_user_id',
                'status', 'attempts', 'failure', 'started_at', 'completed_at', 'created_at',
            ])
            ->map(fn (object $row): array => [
                'job_id' => (string) $row->operation_id,
                'ledger' => 'approval_execution',
                'row_id' => (int) $row->id,
                'owner_user_id' => (int) $row->actor_user_id,
                'site_id' => (int) $row->site_id,
                'type' => 'approval.execution',
                'subject_type' => 'site',
                'subject_id' => (string) $row->site_id,
                'status' => (string) $row->status,
                'progress' => $row->status === 'completed' ? 100 : 0,
                'attempts' => (int) $row->attempts,
                'max_attempts' => null,
                'safe_to_cancel' => in_array($row->status, ['queued', 'running'], true),
                'created_at' => (string) $row->created_at,
                'started_at' => $row->started_at === null ? null : (string) $row->started_at,
                'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
                'error' => $this->safeFailure($row->failure),
                'correlation_id' => (string) $row->correlation_id,
            ]);

        $jobs = $controlPlane->concat($approvalBacked)->values()->all();
        usort($jobs, static function (array $left, array $right): int {
            $created = strcmp((string) $right['created_at'], (string) $left['created_at']);

            return $created !== 0 ? $created : ((int) $right['row_id'] <=> (int) $left['row_id']);
        });

        return $jobs;
    }

    private function safeFailure(?string $failure): ?string
    {
        if ($failure === null) {
            return null;
        }

        return preg_replace(
            '/(?i)\b(password|passwd|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token)\b\s*[:=]\s*[^\s,;]+/',
            '$1=[REDACTED]',
            $failure,
        ) ?? $failure;
    }
}
