<?php

namespace App\AI\Platform\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AutomationCenterService
{
    public const CLAIM_DUE_JOBS_OPERATION_ID = 'AIMW-AI-E8F4848B4A';

    private const CLAIMABLE_TASK_TYPES = ['sync', 'seo_audit'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Atomically claim due tenant-owned automation jobs without dispatching them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimDueJobs(?Carbon $utcNow = null): array
    {
        $utcNow ??= now('UTC');
        $tenantId = $this->context->id();

        return DB::transaction(function () use ($tenantId, $utcNow): array {
            $candidates = DB::table('scheduled_tasks')
                ->where('tenant_id', $tenantId)
                ->where('enabled', true)
                ->whereIn('task_type', self::CLAIMABLE_TASK_TYPES)
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', $utcNow)
                ->where(function ($query): void {
                    $query->whereNull('last_status')->orWhere('last_status', '<>', 'Running');
                })
                ->orderBy('next_run_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $claimed = [];

            foreach ($candidates as $candidate) {
                $updated = DB::table('scheduled_tasks')
                    ->where('id', $candidate->id)
                    ->where('tenant_id', $tenantId)
                    ->where('enabled', true)
                    ->whereIn('task_type', self::CLAIMABLE_TASK_TYPES)
                    ->whereNotNull('next_run_at')
                    ->where('next_run_at', '<=', $utcNow)
                    ->where(function ($query): void {
                        $query->whereNull('last_status')->orWhere('last_status', '<>', 'Running');
                    })
                    ->update([
                        'last_status' => 'Running',
                        'last_run_at' => $utcNow,
                        'updated_at' => $utcNow,
                    ]);

                if ($updated !== 1) {
                    continue;
                }

                $row = DB::table('scheduled_tasks')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $candidate->id)
                    ->first();

                if ($row !== null) {
                    $claimed[] = (array) $row;
                }
            }

            return $claimed;
        });
    }
}
