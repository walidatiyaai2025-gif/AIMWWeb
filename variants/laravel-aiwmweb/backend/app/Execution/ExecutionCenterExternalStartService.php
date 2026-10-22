<?php

namespace App\Execution;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tenant-safe Laravel adaptation of canonical ExecutionCenterService.TryStartExternal.
 *
 * Starting is a control-plane state transition for a previously registered external execution.
 * It does not execute WordPress/provider work and must never manufacture external progress.
 */
final class ExecutionCenterExternalStartService
{
    public const OPERATION_ID = 'AIMW-AUTO-8836C7A28A';

    public const START_MESSAGE = 'Approved change execution started.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function tryStartExternal(string $jobId, int $ownerUserId): bool
    {
        $jobId = trim($jobId);
        if (! Str::isUuid($jobId)) {
            throw new InvalidArgumentException('A valid external execution job UUID is required.');
        }
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('A valid execution owner user ID is required.');
        }

        $this->authorizer->authorize('operations.manage');
        $tenantId = $this->context->id();
        $membership = $this->context->membership();
        if ((int) $membership->user_id !== $ownerUserId) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($jobId, $ownerUserId, $tenantId): bool {
            $execution = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('correlation_id', $jobId)
                ->where('requested_by_user_id', $ownerUserId)
                ->lockForUpdate()
                ->first();

            if ($execution === null || (string) $execution->status !== 'queued') {
                return false;
            }

            $payload = $this->decodeMetadata($execution->payload ?? null);
            if (($payload['execution_mode'] ?? null) !== 'External') {
                return false;
            }

            $now = now();
            $updated = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('correlation_id', $jobId)
                ->where('requested_by_user_id', $ownerUserId)
                ->where('status', 'queued')
                ->update([
                    'status' => 'running',
                    'started_at' => $now,
                    'completed_at' => null,
                    'failure' => null,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return false;
            }

            DB::table('operation_logs')->insert([
                'tenant_id' => $tenantId,
                'operation_execution_id' => (int) $execution->id,
                'correlation_id' => $jobId,
                'level' => 'info',
                'message' => self::START_MESSAGE,
                'context' => json_encode([
                    'source' => 'ExecutionCenterService.TryStartExternal',
                    'operation_id' => self::OPERATION_ID,
                    'owner_user_id' => $ownerUserId,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);

            return true;
        }, 3);
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
}
