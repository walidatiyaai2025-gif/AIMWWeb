<?php

namespace App\Execution;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tenant-safe Laravel adaptation of canonical ExecutionCenterService.Cancel.
 *
 * Cancellation is a control-plane transition only. It marks an existing tracked
 * execution as cancelled so the real worker can observe terminal intent; it does
 * not execute, simulate, or manufacture work or progress.
 */
final class ExecutionCenterCancelService
{
    public const OPERATION_ID = 'AIMW-AUTO-DBF5562324';

    public const CANCEL_MESSAGE = 'Job cancelled by user.';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function cancel(string $jobId, int $ownerUserId): bool
    {
        $jobId = trim($jobId);
        if (! Str::isUuid($jobId)) {
            throw new InvalidArgumentException('A valid execution job UUID is required.');
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

            if ($execution === null) {
                return false;
            }

            $payload = $this->decodeMetadata($execution->payload ?? null);
            if (($payload['execution_mode'] ?? null) !== 'Tracked') {
                return false;
            }

            if (! in_array((string) $execution->status, ['queued', 'running', 'paused'], true)) {
                return false;
            }

            // The Laravel control plane already exposes this explicit safety boundary.
            // Respect it rather than allowing a direct service call to cancel unsafe work.
            if (! (bool) $execution->safe_to_cancel) {
                return false;
            }

            $now = now();
            $updated = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('correlation_id', $jobId)
                ->where('requested_by_user_id', $ownerUserId)
                ->whereIn('status', ['queued', 'running', 'paused'])
                ->where('safe_to_cancel', true)
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return false;
            }

            DB::table('operation_logs')->insert([
                'tenant_id' => $tenantId,
                'operation_execution_id' => (int) $execution->id,
                'correlation_id' => $jobId,
                'level' => 'warning',
                'message' => self::CANCEL_MESSAGE,
                'context' => json_encode([
                    'source' => 'ExecutionCenterService.Cancel',
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
