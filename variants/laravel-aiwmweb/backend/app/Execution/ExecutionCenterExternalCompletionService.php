<?php

namespace App\Execution;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-safe Laravel adaptation of canonical ExecutionCenterService.CompleteExternal.
 *
 * Completion is control-plane only: it records terminal state for a previously registered
 * External execution. It never claims that a provider or WordPress runtime performed work.
 */
final class ExecutionCenterExternalCompletionService
{
    public const OPERATION_ID = 'AIMW-AUTO-0701C84252';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    /** @return array<string, mixed>|null */
    public function completeExternal(int $executionId, int $ownerUserId, string $message): ?array
    {
        if ($executionId <= 0 || $ownerUserId <= 0) {
            return null;
        }

        $this->authorizer->authorize('operations.manage');
        $tenantId = $this->context->id();
        $membership = $this->context->membership();
        if ((int) $membership->user_id !== $ownerUserId) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($executionId, $ownerUserId, $tenantId, $message): ?array {
            $execution = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('id', $executionId)
                ->where('requested_by_user_id', $ownerUserId)
                ->lockForUpdate()
                ->first();

            if ($execution === null || (string) $execution->status !== 'running') {
                return null;
            }

            $payload = $this->decodeMetadata($execution->payload ?? null);
            if (($payload['execution_mode'] ?? null) !== 'External') {
                return null;
            }

            $totalItems = max(1, (int) ($payload['total_items'] ?? 1));
            $payload['processed_items'] = $totalItems;
            $now = now();
            $safeMessage = $this->safeText($message) ?? '';

            $updated = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('id', $executionId)
                ->where('requested_by_user_id', $ownerUserId)
                ->where('status', 'running')
                ->update([
                    'status' => 'completed',
                    'progress' => 100,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'failure' => null,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            DB::table('operation_logs')->insert([
                'tenant_id' => $tenantId,
                'operation_execution_id' => $executionId,
                'correlation_id' => (string) $execution->correlation_id,
                'level' => 'success',
                'message' => $safeMessage,
                'context' => json_encode([
                    'source' => 'ExecutionCenterService.CompleteExternal',
                    'operation_id' => self::OPERATION_ID,
                    'owner_user_id' => $ownerUserId,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);

            $completed = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('id', $executionId)
                ->where('requested_by_user_id', $ownerUserId)
                ->first();

            return $completed === null ? null : $this->project($completed);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function project(object $row): array
    {
        $payload = $this->decodeMetadata($row->payload ?? null);

        return [
            'job_id' => (string) $row->correlation_id,
            'ledger' => 'operation_execution',
            'row_id' => (int) $row->id,
            'owner_user_id' => (int) $row->requested_by_user_id,
            'status' => (string) $row->status,
            'progress' => (int) $row->progress,
            'total_items' => max(1, (int) ($payload['total_items'] ?? 1)),
            'processed_items' => (int) ($payload['processed_items'] ?? 0),
            'execution_mode' => (string) ($payload['execution_mode'] ?? ''),
            'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
            'error' => $this->safeText($row->failure ?? null),
        ];
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

    private function safeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace(
            '/(?i)\b(password|passwd|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token)\b\s*[:=]\s*[^\s,;]+/',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
    }
}
