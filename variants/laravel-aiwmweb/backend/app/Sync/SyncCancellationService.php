<?php

namespace App\Sync;

use App\Models\SyncBatch;
use App\Models\SyncEvent;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class SyncCancellationService
{
    private const ACTIVE_STATES = ['queued', 'running', 'cancel_requested'];

    public function __construct(private readonly SyncLeaseService $leases) {}

    public function activeForSite(int $siteId): ?SyncRun
    {
        return SyncRun::query()
            ->where('site_id', $siteId)
            ->whereIn('state', self::ACTIVE_STATES)
            ->latest('id')
            ->first();
    }

    public function requestForSite(int $siteId): ?SyncRun
    {
        return DB::transaction(function () use ($siteId): ?SyncRun {
            $run = SyncRun::query()
                ->where('site_id', $siteId)
                ->whereIn('state', self::ACTIVE_STATES)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $run) {
                return null;
            }

            if ($run->state !== 'cancel_requested') {
                $run->forceFill(['state' => 'cancel_requested'])->save();
            }

            return $run->fresh();
        }, 3);
    }

    public function finalizeIfRequested(int $syncRunId): bool
    {
        $state = SyncRun::query()->whereKey($syncRunId)->value('state');
        if ($state === 'cancelled') {
            return true;
        }
        if ($state !== 'cancel_requested') {
            return false;
        }

        $this->finalizeRun($syncRunId);

        return true;
    }

    public function finalizeBatchIfRequested(int $syncBatchId): bool
    {
        $runId = SyncBatch::query()->whereKey($syncBatchId)->value('sync_run_id');

        return $runId ? $this->finalizeIfRequested((int) $runId) : false;
    }

    public function finalizeBatch(int $syncBatchId): void
    {
        $runId = SyncBatch::query()->whereKey($syncBatchId)->value('sync_run_id');
        if ($runId) {
            $this->finalizeRun((int) $runId);
        }
    }

    public function finalizeRun(int $syncRunId): void
    {
        $finalized = DB::transaction(function () use ($syncRunId): ?array {
            $run = SyncRun::query()->whereKey($syncRunId)->lockForUpdate()->first();
            if (! $run || $run->state === 'cancelled') {
                return null;
            }
            if ($run->state !== 'cancel_requested') {
                return null;
            }

            SyncBatch::query()
                ->where('sync_run_id', $run->id)
                ->whereIn('state', ['queued', 'running'])
                ->update([
                    'state' => 'cancelled',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            $run->forceFill([
                'state' => 'cancelled',
                'completed_at' => now(),
                'last_error' => null,
            ])->save();

            $payload = ['reason' => 'user_requested'];
            SyncEvent::query()->create([
                'site_id' => $run->site_id,
                'sync_run_id' => $run->id,
                'event_type' => 'SyncCancelled',
                'payload' => $payload,
                'occurred_at' => now(),
            ]);

            return [
                'run' => $run->fresh(),
                'payload' => $payload,
                'site_id' => (int) $run->site_id,
                'lease_token' => (string) $run->lease_token,
            ];
        }, 3);

        if (! $finalized) {
            return;
        }

        $this->leases->release($finalized['site_id'], $finalized['lease_token']);
        Event::dispatch('SyncCancelled', [$finalized['run'], $finalized['payload']]);
    }
}
