<?php

namespace App\Automation;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ExecutionCenterService
{
    public const PAUSE_OPERATION_ID = 'AIMW-AUTO-FF4812A204';

    public const RESUME_OPERATION_ID = 'AIMW-AUTO-E57D5E6134';

    public function __construct(private readonly TenantContext $context) {}

    public function pause(string $jobId, ?int $ownerUserId = null): bool
    {
        return $this->transition($jobId, $ownerUserId, 'running', 'paused', self::PAUSE_OPERATION_ID, 'Execution paused by user.');
    }

    public function resume(string $jobId, ?int $ownerUserId = null): bool
    {
        return $this->transition($jobId, $ownerUserId, 'paused', 'running', self::RESUME_OPERATION_ID, 'Execution resumed by user.');
    }

    private function transition(string $jobId, ?int $ownerUserId, string $from, string $to, string $operationId, string $message): bool
    {
        $tenantId = $this->context->id();

        return DB::transaction(function () use ($tenantId, $jobId, $ownerUserId, $from, $to, $operationId, $message): bool {
            $query = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('correlation_id', $jobId);
            if ($ownerUserId !== null) {
                $query->where('requested_by_user_id', $ownerUserId);
            }
            $execution = $query->lockForUpdate()->first();
            if ($execution === null) {
                throw (new ModelNotFoundException)->setModel('operation_execution');
            }
            if (strtolower((string) $execution->status) !== $from) {
                return false;
            }

            $now = now();
            DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('id', $execution->id)
                ->update(['status' => $to, 'updated_at' => $now]);
            DB::table('operation_logs')->insert([
                'tenant_id' => $tenantId,
                'operation_execution_id' => $execution->id,
                'correlation_id' => $jobId,
                'level' => 'info',
                'message' => $message,
                'context' => json_encode(['operation_id' => $operationId, 'source' => 'ExecutionCenterService'], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);

            return true;
        }, 3);
    }
}
