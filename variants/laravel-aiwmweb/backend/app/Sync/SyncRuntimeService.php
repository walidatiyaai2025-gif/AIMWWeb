<?php

namespace App\Sync;

use App\Content\Remote\ContentRemoteDriver;
use App\Jobs\ProcessSyncBatchJob;
use App\Jobs\ProcessSyncRunJob;
use App\Jobs\RetrySyncItemJob;
use App\Models\ContentConflict;
use App\Models\ContentSyncState;
use App\Models\SyncBatch;
use App\Models\SyncEvent;
use App\Models\SyncItem;
use App\Models\SyncResourceVersion;
use App\Models\SyncRun;
use App\Models\SyncTombstone;
use App\Sync\Contracts\SyncSiteGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SyncRuntimeService
{
    public const RESOURCES = ['posts', 'pages', 'media', 'categories', 'tags', 'comments'];

    public function __construct(
        private readonly ContentRemoteDriver $remote,
        private readonly SyncResourceProjector $projector,
        private readonly SyncLeaseService $leases,
        private readonly SyncSiteGuard $sites,
    ) {}

    public function start(
        int $tenantId,
        int $siteId,
        bool $full = false,
        array $resources = self::RESOURCES,
        string $trigger = 'manual',
        array $metadata = [],
        ?int $actorUserId = null,
        ?int $resumeOf = null,
    ): SyncRun {
        $this->sites->assertAccessible($siteId);
        $resources = $this->normalizeResources($resources);

        if (SyncRun::query()->where('site_id', $siteId)->whereIn('state', ['queued', 'running'])->exists()) {
            throw new RuntimeException('A sync is already active for this site.');
        }

        $run = SyncRun::query()->create([
            'site_id' => $siteId,
            'mode' => $full ? 'full' : ($trigger === 'webhook' ? 'webhook' : 'incremental'),
            'trigger' => $trigger,
            'state' => 'queued',
            'resources' => $resources,
            'metadata' => $metadata,
            'initiated_by_user_id' => $actorUserId,
            'resume_of_sync_run_id' => $resumeOf,
            'lease_token' => (string) Str::uuid(),
        ]);

        ProcessSyncRunJob::dispatch($tenantId, $run->id);

        return $run;
    }

    public function resume(int $tenantId, SyncRun $run, ?int $actorUserId = null): SyncRun
    {
        if (! in_array($run->state, ['partial', 'failed', 'cancelled'], true)) {
            throw new RuntimeException('Only partial, failed or cancelled sync runs can be resumed.');
        }

        return $this->start(
            $tenantId,
            $run->site_id,
            $run->mode === 'full',
            $run->resources,
            'resume',
            ['resume_of' => $run->id],
            $actorUserId,
            $run->id,
        );
    }

    public function startWebhook(int $tenantId, array $event, int $webhookEventId): SyncRun
    {
        $resource = (string) ($event['resource'] ?? '');
        if (! in_array($resource, self::RESOURCES, true)) {
            throw new InvalidArgumentException('Webhook resource is not syncable.');
        }

        return $this->start(
            $tenantId,
            (int) $event['site_id'],
            false,
            [$resource],
            'webhook',
            [
                'webhook_event_id' => $webhookEventId,
                'remote_id' => (int) ($event['remote_id'] ?? 0),
                'action' => (string) ($event['action'] ?? 'updated'),
                'event_id' => (string) ($event['event_id'] ?? ''),
            ],
        );
    }

    public function processRun(int $tenantId, int $runId): void
    {
        $run = SyncRun::query()->findOrFail($runId);
        if (in_array($run->state, ['completed', 'cancelled'], true)) {
            return;
        }

        if (! $this->leases->acquire($run->site_id, $run->lease_token, 'sync-run')) {
            throw new RuntimeException('Site sync lease is held by another operation.');
        }

        $run->forceFill([
            'state' => 'running',
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ])->save();
        $this->emit($run, 'SyncStarted', ['mode' => $run->mode, 'trigger' => $run->trigger]);

        $batches = [];
        foreach ($run->resources as $resource) {
            $checkpoint = ContentSyncState::query()->firstOrCreate(
                ['site_id' => $run->site_id, 'resource' => $resource],
                ['state' => 'idle'],
            );
            $cursor = ['page' => 1];
            if ($run->mode !== 'full' && $checkpoint->last_remote_modified_at) {
                $cursor['modified_after'] = $checkpoint->last_remote_modified_at->toIso8601String();
            }
            if ($run->mode === 'webhook') {
                $cursor['remote_id'] = (int) data_get($run->metadata, 'remote_id', 0);
                $cursor['action'] = (string) data_get($run->metadata, 'action', 'updated');
            }

            $batches[] = SyncBatch::query()->firstOrCreate(
                ['sync_run_id' => $run->id, 'resource' => $resource, 'page' => 1],
                ['site_id' => $run->site_id, 'state' => 'queued', 'cursor' => $cursor],
            );
            $checkpoint->forceFill(['state' => 'running', 'started_at' => now(), 'last_error' => null])->save();
        }

        foreach ($batches as $batch) {
            ProcessSyncBatchJob::dispatch($tenantId, $batch->id);
        }
    }

    public function processBatch(int $tenantId, int $batchId): void
    {
        $batch = SyncBatch::query()->with('run')->findOrFail($batchId);
        $run = $batch->run;
        if (! $run || in_array($run->state, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }
        if (! $this->leases->refresh($run->site_id, $run->lease_token)) {
            throw new RuntimeException('Sync site lease was lost.');
        }

        $batch->forceFill([
            'state' => 'running',
            'attempts' => $batch->attempts + 1,
            'started_at' => $batch->started_at ?? now(),
            'last_error' => null,
        ])->save();

        $cursor = $batch->cursor ?? [];
        $rows = $this->pullRows($run, $batch, $cursor);
        $batch->forceFill(['received_count' => count($rows)])->save();
        $run->increment('discovered_count', count($rows));

        $maxModified = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $remoteId = $this->projector->remoteId($row);
            $item = SyncItem::query()->create([
                'site_id' => $run->site_id,
                'sync_run_id' => $run->id,
                'sync_batch_id' => $batch->id,
                'resource' => $batch->resource,
                'remote_id' => $remoteId ?: null,
                'state' => 'running',
                'attempts' => 1,
                'remote_payload' => $row,
            ]);

            try {
                $outcome = $this->reconcileRow($run, $batch->resource, $row);
                $item->forceFill(['state' => 'completed', 'action' => $outcome, 'processed_at' => now()])->save();
                $batch->increment('processed_count');
                $this->incrementOutcome($run, $outcome);
            } catch (Throwable $exception) {
                $item->forceFill(['state' => 'failed', 'last_error' => $this->safeError($exception), 'processed_at' => now()])->save();
                $batch->increment('failed_count');
                $run->increment('failed_count');
            }

            $modified = $this->projector->remoteModifiedAt($row);
            if ($modified && (! $maxModified || $modified->greaterThan($maxModified))) {
                $maxModified = $modified;
            }
        }

        if ($run->mode === 'full' && count($rows) < 100 && ! SyncItem::query()->where('sync_run_id', $run->id)->where('resource', $batch->resource)->where('state', 'failed')->exists()) {
            $missing = $this->reconcileMissing($run, $batch->resource);
            if ($missing['deleted'] > 0) {
                $run->increment('deleted_count', $missing['deleted']);
            }
            if ($missing['conflicted'] > 0) {
                $run->increment('conflicted_count', $missing['conflicted']);
            }
        }

        $checkpoint = ContentSyncState::query()->where('site_id', $run->site_id)->where('resource', $batch->resource)->firstOrFail();
        if ($maxModified && (! $checkpoint->last_remote_modified_at || $maxModified->greaterThan($checkpoint->last_remote_modified_at))) {
            $checkpoint->last_remote_modified_at = $maxModified;
        }
        $checkpoint->cursor = json_encode(['page' => $batch->page], JSON_THROW_ON_ERROR);
        $checkpoint->progress = count($rows) === 100 ? min(99, $batch->page * 10) : 100;
        $checkpoint->save();

        $next = null;
        if ($run->mode !== 'webhook' && count($rows) === 100) {
            $nextCursor = $cursor;
            $nextCursor['page'] = $batch->page + 1;
            $next = SyncBatch::query()->firstOrCreate(
                ['sync_run_id' => $run->id, 'resource' => $batch->resource, 'page' => $batch->page + 1],
                ['site_id' => $run->site_id, 'state' => 'queued', 'cursor' => $nextCursor],
            );
            $batch->next_cursor = $nextCursor;
        } else {
            $checkpoint->forceFill(['state' => 'succeeded', 'progress' => 100, 'completed_at' => now()])->save();
        }

        $batch->forceFill(['state' => 'completed', 'completed_at' => now()])->save();
        $this->emit($run, 'SyncProgressed', [
            'resource' => $batch->resource,
            'page' => $batch->page,
            'received' => count($rows),
            'failed' => $batch->failed_count,
        ]);

        if ($next) {
            ProcessSyncBatchJob::dispatch($tenantId, $next->id);
        }

        $this->completeIfReady($run);
    }

    public function recordRunFailure(int $runId, Throwable $exception, bool $terminal): void
    {
        $run = SyncRun::query()->find($runId);
        if (! $run) {
            return;
        }

        $run->forceFill(['last_error' => $this->safeError($exception)])->save();
        if (! $terminal) {
            return;
        }

        $run->forceFill(['state' => 'failed', 'completed_at' => now()])->save();
        if ($run->lease_token) {
            $this->leases->release($run->site_id, $run->lease_token);
        }
        $this->emit($run, 'SyncFailed', ['error' => $run->last_error]);
    }

    public function recordBatchFailure(int $batchId, Throwable $exception, bool $terminal): void
    {
        $batch = SyncBatch::query()->with('run')->find($batchId);
        if (! $batch || ! $batch->run) {
            return;
        }

        $batch->forceFill(['last_error' => $this->safeError($exception)])->save();
        if (! $terminal) {
            return;
        }

        $batch->forceFill(['state' => 'failed', 'completed_at' => now()])->save();
        $run = $batch->run;
        $run->increment('failed_count');
        $completed = $run->batches()->where('state', 'completed')->exists();
        $run->forceFill([
            'state' => $completed ? 'partial' : 'failed',
            'last_error' => $this->safeError($exception),
            'completed_at' => now(),
        ])->save();
        $this->leases->release($run->site_id, $run->lease_token);
        $this->emit($run, 'SyncFailed', ['batch_id' => $batch->id, 'error' => $run->last_error]);
    }

    public function retryItem(int $tenantId, SyncItem $item): void
    {
        if ($item->state !== 'failed') {
            throw new RuntimeException('Only failed sync items can be retried.');
        }

        $item->forceFill(['state' => 'queued', 'last_error' => null])->save();
        RetrySyncItemJob::dispatch($tenantId, $item->id);
    }

    public function processRetryItem(int $itemId): void
    {
        $item = SyncItem::query()->findOrFail($itemId);
        $run = SyncRun::query()->findOrFail($item->sync_run_id);
        $item->increment('attempts');
        $item->forceFill(['state' => 'running'])->save();

        try {
            $outcome = $this->reconcileRow($run, $item->resource, $item->remote_payload ?? []);
            $item->forceFill(['state' => 'completed', 'action' => $outcome, 'processed_at' => now(), 'last_error' => null])->save();
            if ($run->failed_count > 0) {
                $run->decrement('failed_count');
            }
            if ($item->sync_batch_id) {
                SyncBatch::query()->whereKey($item->sync_batch_id)->where('failed_count', '>', 0)->decrement('failed_count');
            }
            $this->completeIfReady($run->fresh());
        } catch (Throwable $exception) {
            $item->forceFill(['state' => 'failed', 'last_error' => $this->safeError($exception), 'processed_at' => now()])->save();
            throw $exception;
        }
    }

    public function resolveConflict(ContentConflict $conflict, string $strategy, array $manualPayload, ?int $userId): ContentConflict
    {
        $strategy = strtoupper($strategy);
        if (! in_array($strategy, ['KEEP_REMOTE', 'KEEP_LOCAL', 'MANUAL', 'RETRY_RECONCILIATION'], true)) {
            throw new InvalidArgumentException('Unsupported conflict resolution strategy.');
        }
        if ($conflict->status !== 'open') {
            throw new RuntimeException('Conflict is already resolved.');
        }

        if ($strategy === 'RETRY_RECONCILIATION') {
            $this->start(
                (int) $conflict->tenant_id,
                (int) $conflict->site_id,
                false,
                [(string) $conflict->resource],
                'conflict-retry',
                ['conflict_id' => $conflict->id],
                $userId,
            );
            $conflict->forceFill([
                'status' => 'resolved',
                'resolution' => $strategy,
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
            ])->save();

            return $conflict->fresh();
        }

        $resource = (string) $conflict->resource;
        $remoteId = (int) $conflict->remote_id;
        $local = $this->projector->findLocal($conflict->site_id, $resource, $remoteId);

        if ($strategy === 'KEEP_REMOTE') {
            $remote = $this->remote->get($conflict->site_id, $resource, $remoteId);
        } elseif ($strategy === 'KEEP_LOCAL') {
            if (! $local) {
                throw new RuntimeException('Local object is unavailable.');
            }
            $this->remote->mutate($conflict->site_id, $resource, $remoteId, 'update', $this->projector->localPayload($local));
            $remote = $this->remote->get($conflict->site_id, $resource, $remoteId);
        } else {
            if ($manualPayload === []) {
                throw new InvalidArgumentException('MANUAL resolution requires an explicit payload.');
            }
            $this->remote->mutate($conflict->site_id, $resource, $remoteId, 'update', $manualPayload);
            $remote = $this->remote->get($conflict->site_id, $resource, $remoteId);
        }

        $projected = $this->projector->project($conflict->site_id, $resource, $remote);
        $this->writeBaseline($conflict->site_id, $resource, $remoteId, $projected, $remote, null);
        $conflict->forceFill([
            'status' => 'resolved',
            'resolution' => $strategy,
            'resolved_by_user_id' => $userId,
            'resolved_at' => now(),
            'remote_snapshot' => $remote,
            'remote_hash' => $this->projector->remoteHash($remote),
        ])->save();

        return $conflict->fresh();
    }

    public function confirmWebhookDeletion(int $siteId, string $resource, int $remoteId, array $evidence = []): string
    {
        $version = SyncResourceVersion::query()
            ->where('site_id', $siteId)
            ->where('resource', $resource)
            ->where('remote_id', $remoteId)
            ->first();

        if (! $version) {
            return 'unchanged';
        }

        return $this->confirmDeletion($version, ['policy' => 'verified-webhook'] + $evidence);
    }

    private function pullRows(SyncRun $run, SyncBatch $batch, array $cursor): array
    {
        if ($run->mode === 'webhook') {
            $remoteId = (int) ($cursor['remote_id'] ?? 0);
            $action = strtolower((string) ($cursor['action'] ?? 'updated'));
            if ($action === 'deleted' || $action === 'delete') {
                $outcome = $this->confirmWebhookDeletion($run->site_id, $batch->resource, $remoteId, ['run_id' => $run->id]);
                $this->incrementOutcome($run, $outcome);

                return [];
            }
            if ($remoteId < 1) {
                throw new InvalidArgumentException('Webhook sync requires a remote id.');
            }

            return [$this->remote->get($run->site_id, $batch->resource, $remoteId)];
        }

        $query = ['per_page' => 100, 'page' => (int) ($cursor['page'] ?? 1)];
        if (! empty($cursor['modified_after'])) {
            $query['modified_after'] = $cursor['modified_after'];
        }

        return $this->remote->list($run->site_id, $batch->resource, $query);
    }

    private function reconcileRow(SyncRun $run, string $resource, array $remote): string
    {
        $remoteId = $this->projector->remoteId($remote);
        if ($remoteId < 1) {
            throw new InvalidArgumentException('Remote item id is missing.');
        }

        return DB::transaction(function () use ($run, $resource, $remote, $remoteId): string {
            $version = SyncResourceVersion::query()
                ->where('site_id', $run->site_id)
                ->where('resource', $resource)
                ->where('remote_id', $remoteId)
                ->lockForUpdate()
                ->first();
            $local = $this->projector->findLocal($run->site_id, $resource, $remoteId);
            $remoteHash = $this->projector->remoteHash($remote);
            $localHash = $local ? $this->projector->localHash($local) : null;
            $baseRemote = $version?->base_remote_hash ?? ($local?->remote_hash ?: null);
            $baseLocal = $version?->base_local_hash;
            $remoteChanged = $baseRemote !== null && ! hash_equals($baseRemote, $remoteHash);
            $localChanged = $local && $this->isLocalChanged($local, $baseLocal, $localHash);

            if ($remoteChanged && $localChanged) {
                $this->createConflict($run, $resource, $remoteId, $local, $remote, $version, $localHash, $remoteHash);
                if ($version) {
                    $version->forceFill(['last_seen_sync_run_id' => $run->id, 'last_seen_at' => now()])->save();
                }

                return 'conflicted';
            }

            if (! $local || $remoteChanged) {
                $projected = $this->projector->project($run->site_id, $resource, $remote);
                $this->writeBaseline($run->site_id, $resource, $remoteId, $projected, $remote, $run->id);

                return $local ? 'updated' : 'created';
            }

            if ($localChanged) {
                $version?->forceFill(['last_seen_sync_run_id' => $run->id, 'last_seen_at' => now()])->save();
                $this->emit($run, 'LocalModificationDetected', ['resource' => $resource, 'remote_id' => $remoteId]);

                return 'unchanged';
            }

            $version ??= new SyncResourceVersion([
                'site_id' => $run->site_id,
                'resource' => $resource,
                'remote_id' => $remoteId,
            ]);
            $version->fill([
                'local_model_type' => class_basename($local),
                'local_model_id' => $local->id,
                'base_local_hash' => $localHash,
                'base_remote_hash' => $remoteHash,
                'remote_version' => $this->projector->remoteVersion($remote),
                'remote_modified_at' => $this->projector->remoteModifiedAt($remote),
                'last_seen_sync_run_id' => $run->id,
                'last_seen_at' => now(),
                'tombstoned_at' => null,
            ])->save();
            SyncTombstone::query()->where('site_id', $run->site_id)->where('resource', $resource)->where('remote_id', $remoteId)->delete();

            return 'unchanged';
        }, 3);
    }

    private function reconcileMissing(SyncRun $run, string $resource): array
    {
        $deleted = 0;
        $conflicted = 0;
        $versions = SyncResourceVersion::query()
            ->where('site_id', $run->site_id)
            ->where('resource', $resource)
            ->whereNull('tombstoned_at')
            ->where(function ($query) use ($run) {
                $query->whereNull('last_seen_sync_run_id')->orWhere('last_seen_sync_run_id', '!=', $run->id);
            })
            ->orderBy('id')
            ->cursor();

        foreach ($versions as $version) {
            $tombstone = SyncTombstone::query()->firstOrNew([
                'site_id' => $run->site_id,
                'resource' => $resource,
                'remote_id' => $version->remote_id,
            ]);
            $tombstone->missing_observations = $tombstone->exists ? $tombstone->missing_observations + 1 : 1;
            $tombstone->first_missing_at ??= now();
            $tombstone->last_checked_at = now();
            $tombstone->evidence = ['policy' => 'two-full-scans', 'last_run_id' => $run->id];
            $tombstone->save();

            if ($tombstone->missing_observations < 2) {
                continue;
            }

            $outcome = $this->confirmDeletion($version, ['policy' => 'two-full-scans', 'run_id' => $run->id]);
            if ($outcome === 'conflicted') {
                $conflicted++;
            } else {
                $deleted++;
            }
        }

        return compact('deleted', 'conflicted');
    }

    private function confirmDeletion(SyncResourceVersion $version, array $evidence): string
    {
        $local = $this->projector->findLocal($version->site_id, $version->resource, $version->remote_id);
        $localHash = $local ? $this->projector->localHash($local) : null;
        if ($local && $this->isLocalChanged($local, $version->base_local_hash, $localHash)) {
            $run = SyncRun::query()->where('site_id', $version->site_id)->latest('id')->first();
            if ($run) {
                $this->createConflict(
                    $run,
                    $version->resource,
                    $version->remote_id,
                    $local,
                    ['deleted' => true, 'evidence' => $evidence],
                    $version,
                    $localHash,
                    hash('sha256', 'deleted'),
                );
            }

            return 'conflicted';
        }

        $version->forceFill(['tombstoned_at' => now()])->save();
        if ($local) {
            $this->projector->markRemoteDeleted($local);
        }
        SyncTombstone::query()->updateOrCreate(
            ['site_id' => $version->site_id, 'resource' => $version->resource, 'remote_id' => $version->remote_id],
            [
                'missing_observations' => 2,
                'first_missing_at' => now(),
                'last_checked_at' => now(),
                'confirmed_deleted_at' => now(),
                'evidence' => $evidence,
            ],
        );

        return 'deleted';
    }

    private function writeBaseline(int $siteId, string $resource, int $remoteId, Model $local, array $remote, ?int $runId): void
    {
        SyncResourceVersion::query()->updateOrCreate(
            ['site_id' => $siteId, 'resource' => $resource, 'remote_id' => $remoteId],
            [
                'local_model_type' => class_basename($local),
                'local_model_id' => $local->id,
                'base_local_hash' => $this->projector->localHash($local),
                'base_remote_hash' => $this->projector->remoteHash($remote),
                'remote_version' => $this->projector->remoteVersion($remote),
                'remote_modified_at' => $this->projector->remoteModifiedAt($remote),
                'last_seen_sync_run_id' => $runId,
                'last_seen_at' => now(),
                'tombstoned_at' => null,
            ],
        );
        SyncTombstone::query()->where('site_id', $siteId)->where('resource', $resource)->where('remote_id', $remoteId)->delete();
    }

    private function createConflict(
        SyncRun $run,
        string $resource,
        int $remoteId,
        Model $local,
        array $remote,
        ?SyncResourceVersion $version,
        ?string $localHash,
        string $remoteHash,
    ): ContentConflict {
        $existing = ContentConflict::query()
            ->where('site_id', $run->site_id)
            ->where('resource', $resource)
            ->where('remote_id', $remoteId)
            ->where('status', 'open')
            ->first();
        if ($existing) {
            return $existing;
        }

        $conflict = ContentConflict::query()->create([
            'site_id' => $run->site_id,
            'resource' => $resource,
            'entity_type' => class_basename($local),
            'entity_id' => $local->id,
            'remote_id' => $remoteId,
            'expected_modified_at' => $version?->remote_modified_at,
            'remote_modified_at' => $this->projector->remoteModifiedAt($remote),
            'expected_version' => $version?->remote_version,
            'remote_version' => $this->projector->remoteVersion($remote),
            'expected_hash' => $version?->base_remote_hash,
            'remote_hash' => $remoteHash,
            'local_hash' => $localHash,
            'local_version' => $local->updated_at?->toIso8601String(),
            'local_snapshot' => $local->toArray(),
            'remote_snapshot' => $remote,
            'detected_at' => now(),
        ]);
        $this->emit($run, 'SyncConflictDetected', ['conflict_id' => $conflict->id, 'resource' => $resource, 'remote_id' => $remoteId]);

        return $conflict;
    }

    private function isLocalChanged(Model $local, ?string $baseLocalHash, ?string $localHash): bool
    {
        if ($baseLocalHash && $localHash) {
            return ! hash_equals($baseLocalHash, $localHash);
        }

        return $local->synced_at && $local->updated_at && $local->updated_at->greaterThan($local->synced_at);
    }

    private function completeIfReady(SyncRun $run): void
    {
        $run->refresh();
        if ($run->batches()->whereIn('state', ['queued', 'running'])->exists()) {
            return;
        }
        if ($run->batches()->where('state', 'failed')->exists()) {
            return;
        }

        $state = $run->failed_count > 0 ? 'partial' : 'completed';
        $run->forceFill(['state' => $state, 'completed_at' => now()])->save();
        $this->leases->release($run->site_id, $run->lease_token);
        $this->emit($run, 'SyncCompleted', [
            'state' => $state,
            'counts' => [
                'discovered' => $run->discovered_count,
                'created' => $run->created_count,
                'updated' => $run->updated_count,
                'unchanged' => $run->unchanged_count,
                'conflicted' => $run->conflicted_count,
                'deleted' => $run->deleted_count,
                'failed' => $run->failed_count,
            ],
        ]);
    }

    private function emit(SyncRun $run, string $eventType, array $payload): void
    {
        SyncEvent::query()->create([
            'site_id' => $run->site_id,
            'sync_run_id' => $run->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
        Event::dispatch($eventType, [$run->fresh(), $payload]);
    }

    private function incrementOutcome(SyncRun $run, string $outcome): void
    {
        $column = match ($outcome) {
            'created' => 'created_count',
            'updated' => 'updated_count',
            'conflicted' => 'conflicted_count',
            'deleted' => 'deleted_count',
            default => 'unchanged_count',
        };
        $run->increment($column);
    }

    private function normalizeResources(array $resources): array
    {
        $resources = array_values(array_unique(array_map('strval', $resources)));
        if ($resources === [] || array_diff($resources, self::RESOURCES)) {
            throw new InvalidArgumentException('Invalid sync resource selection.');
        }

        return $resources;
    }

    private function safeError(Throwable $exception): string
    {
        return Str::limit($exception::class.': '.$exception->getMessage(), 1000);
    }
}
