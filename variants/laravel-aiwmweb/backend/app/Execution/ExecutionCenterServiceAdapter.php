<?php

namespace App\Execution;

use App\Models\Execution;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Laravel adaptations of the canonical ExecutionCenterService read operations.
 *
 * AIMWWeb keeps tracked and external work in existing tenant-owned ledgers. The Laravel
 * variant composes those stores instead of creating a competing execution/activity ledger.
 */
final class ExecutionCenterServiceAdapter
{
    public const OPERATION_ID = 'AIMW-AUTO-035FFB3624';

    public const GET_ACTIVITIES_OPERATION_ID = 'AIMW-AUTO-97A9F6A324';

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

    /**
     * Return immutable execution activity for the active tenant and one owner, newest first.
     *
     * `operation_logs` is the control-plane activity ledger. Approved/external execution
     * activity is recorded as immutable `audit_events`; those events are admitted only after
     * resolving the exact active-tenant execution IDs owned by the caller. This preserves the
     * source invariant that an activity is readable only through an owned job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActivities(int $ownerUserId, int $take = 30): array
    {
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('A valid execution owner user ID is required.');
        }

        $tenantId = $this->context->id();
        $take = max(1, min(1000, $take));

        $controlPlane = DB::table('operation_logs as activity')
            ->join('operation_executions as execution', 'execution.id', '=', 'activity.operation_execution_id')
            ->where('activity.tenant_id', $tenantId)
            ->where('execution.tenant_id', $tenantId)
            ->where('execution.requested_by_user_id', $ownerUserId)
            ->get([
                'activity.id as activity_row_id',
                'activity.correlation_id',
                'activity.level',
                'activity.message',
                'activity.context',
                'activity.occurred_at',
                'execution.type as execution_type',
            ])
            ->map(function (object $row): array {
                $context = $this->decodeMetadata($row->context);

                return [
                    'activity_id' => 'operation-log:'.(int) $row->activity_row_id,
                    'job_id' => (string) $row->correlation_id,
                    'ledger' => 'operation_log',
                    'row_id' => (int) $row->activity_row_id,
                    'occurred_at' => (string) $row->occurred_at,
                    'level' => $this->normalizeActivityLevel((string) $row->level),
                    'type' => (string) ($context['type'] ?? $row->execution_type),
                    'message' => $this->safeFailure((string) $row->message) ?? '',
                    'source' => (string) ($context['source'] ?? 'operation_log'),
                ];
            });

        $ownedExecutions = DB::table('executions')
            ->where('tenant_id', $tenantId)
            ->where('actor_user_id', $ownerUserId)
            ->get(['id', 'operation_id'])
            ->keyBy(fn (object $row): string => (string) $row->id);

        $approvalBacked = collect();
        if ($ownedExecutions->isNotEmpty()) {
            $approvalBacked = DB::table('audit_events')
                ->where('tenant_id', $tenantId)
                ->where('actor_user_id', $ownerUserId)
                ->where('subject_type', Execution::class)
                ->whereIn('subject_id', $ownedExecutions->keys()->all())
                ->get(['id', 'event', 'subject_id', 'metadata', 'occurred_at'])
                ->map(function (object $row) use ($ownedExecutions): array {
                    $metadata = $this->decodeMetadata($row->metadata);
                    $execution = $ownedExecutions->get((string) $row->subject_id);

                    return [
                        'activity_id' => 'audit-event:'.(int) $row->id,
                        'job_id' => (string) $execution->operation_id,
                        'ledger' => 'audit_event',
                        'row_id' => (int) $row->id,
                        'occurred_at' => (string) $row->occurred_at,
                        'level' => $this->normalizeActivityLevel((string) ($metadata['level'] ?? 'Info')),
                        'type' => (string) ($metadata['type'] ?? $row->event),
                        'message' => $this->safeFailure((string) ($metadata['message'] ?? $row->event)) ?? '',
                        'source' => (string) ($metadata['source'] ?? 'audit_event'),
                    ];
                });
        }

        $activities = $controlPlane->concat($approvalBacked)->values()->all();
        usort($activities, static function (array $left, array $right): int {
            $occurred = strcmp((string) $right['occurred_at'], (string) $left['occurred_at']);
            if ($occurred !== 0) {
                return $occurred;
            }

            $ledger = strcmp((string) $right['ledger'], (string) $left['ledger']);

            return $ledger !== 0 ? $ledger : ((int) $right['row_id'] <=> (int) $left['row_id']);
        });

        return array_slice($activities, 0, $take);
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeActivityLevel(string $level): string
    {
        return match (strtolower(trim($level))) {
            'success', 'succeeded', 'completed' => 'Success',
            'warning', 'warn', 'cancelled', 'canceled' => 'Warning',
            'error', 'failed', 'failure', 'critical' => 'Error',
            default => 'Info',
        };
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
