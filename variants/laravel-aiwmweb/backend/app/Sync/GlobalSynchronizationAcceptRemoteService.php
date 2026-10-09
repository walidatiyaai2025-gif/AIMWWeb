<?php

namespace App\Sync;

use App\Models\IdempotencyKey;
use App\Models\SyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GlobalSynchronizationAcceptRemoteService
{
    public const OPERATION_ID = 'AIMW-BILL-6928C148FF';

    public function __construct(
        private readonly SyncRuntimeService $runtime,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{run: SyncRun, replay: bool}
     */
    public function accept(int $siteId, string $idempotencyKey, ?int $actorUserId): array
    {
        $tenantId = $this->tenant->id();
        $requestHash = hash('sha256', json_encode([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'operation_id' => self::OPERATION_ID,
            'mode' => 'full',
            'resources' => SyncRuntimeService::RESOURCES,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenantId, $siteId, $idempotencyKey, $requestHash, $actorUserId): array {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

            $receipt = IdempotencyKey::query()
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($receipt) {
                if ($receipt->operation !== self::OPERATION_ID || ! hash_equals((string) $receipt->request_hash, $requestHash)) {
                    throw new ConflictHttpException('Idempotency key was already used for a different request.');
                }

                $runId = (int) (($receipt->response ?? [])['sync_run_id'] ?? 0);
                if ($runId < 1) {
                    throw new ConflictHttpException('Idempotency receipt is incomplete.');
                }

                return [
                    'run' => SyncRun::query()->findOrFail($runId),
                    'replay' => true,
                ];
            }

            $run = $this->runtime->start(
                $tenantId,
                $siteId,
                true,
                SyncRuntimeService::RESOURCES,
                'accept-remote',
                ['canonical_operation_id' => self::OPERATION_ID],
                $actorUserId,
            );

            IdempotencyKey::query()->create([
                'key' => $idempotencyKey,
                'operation' => self::OPERATION_ID,
                'request_hash' => $requestHash,
                'response' => ['sync_run_id' => (int) $run->getKey()],
                'completed_at' => now(),
            ]);

            return ['run' => $run->fresh(), 'replay' => false];
        }, 3);
    }
}
