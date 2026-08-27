<?php

namespace App\Sync;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncFallbackReconciler
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SyncRuntimeService $runtime,
    ) {}

    public function dispatchDue(int $limit = 200): array
    {
        $targets = DB::table('content_sync_states')
            ->select(['tenant_id', 'site_id'])
            ->where(function ($query) {
                $query->whereNull('completed_at')->orWhere('completed_at', '<=', now()->subMinutes(15));
            })
            ->groupBy('tenant_id', 'site_id')
            ->orderBy('tenant_id')
            ->orderBy('site_id')
            ->limit($limit)
            ->get();

        $result = ['considered' => $targets->count(), 'queued' => 0, 'skipped' => 0];
        foreach ($targets as $target) {
            $tenant = Tenant::query()->find($target->tenant_id);
            if (! $tenant) {
                $result['skipped']++;
                continue;
            }

            $this->context->activate($tenant);
            try {
                $this->runtime->start(
                    $tenant->id,
                    (int) $target->site_id,
                    false,
                    SyncRuntimeService::RESOURCES,
                    'scheduled',
                    ['fallback' => true],
                );
                $result['queued']++;
            } catch (Throwable) {
                $result['skipped']++;
            } finally {
                $this->context->forget();
            }
        }

        return $result;
    }
}
