<?php

namespace App\Sites;

use App\Models\SiteOperationHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SiteOperationHistoryService
{
    private const MAX_RECORDS = 2000;

    public function get(int $siteId, int $take = 100): array
    {
        return SiteOperationHistory::query()->where('site_id', $siteId)
            ->orderByDesc('started_at')->orderByDesc('id')
            ->limit(max(1, min(500, $take)))->get()
            ->map(fn (SiteOperationHistory $item): array => $this->safeSiteItem($item))->all();
    }

    /**
     * Opt-in server-side history paging. The legacy get() contract is unchanged.
     * All filters are exact allowlisted values and the active site is mandatory.
     */
    public function paginateForSite(int $siteId, int $page = 1, int $perPage = 25, ?string $status = null, ?string $operation = null): array
    {
        // Enforce HTTP-equivalent pagination limits at the service boundary too:
        // queue jobs and other internal callers do not pass controller validation.
        $page = max(1, min(1000000, $page));
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        $query = SiteOperationHistory::query()->where('site_id', $siteId);

        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($operation !== null) {
            $query->where('operation', $operation);
        }

        $query->orderByDesc('started_at')->orderByDesc('id');
        // Filtered count first prevents a massive, unnecessary OFFSET query
        // for stale links while retaining the original site scope and order.
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $items = $query->forPage($page, $perPage)->get();

        return [
            'items' => $items->map(fn (SiteOperationHistory $item): array => $this->safeSiteItem($item))->all(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total === 0 ? null : (($page - 1) * $perPage + 1),
            'to' => $total === 0 ? null : min($total, $page * $perPage),
        ];
    }

    public function getAll(int $take = 250): array
    {
        return SiteOperationHistory::query()
            ->with('site:id,name')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->limit(max(1, min(self::MAX_RECORDS, $take)))
            ->get()
            ->map(fn (SiteOperationHistory $item): array => $this->safeSiteItem($item))
            ->all();
    }

    public function getById(int $id): ?SiteOperationHistory
    {
        return SiteOperationHistory::query()->find($id);
    }

    public function getByCorrelationId(string $correlationId): ?SiteOperationHistory
    {
        if (! Str::isUuid($correlationId)) {
            return null;
        }

        return SiteOperationHistory::query()
            ->where('correlation_id', $correlationId)
            ->first();
    }

    public function getSummary(?\DateTimeInterface $since = null): array
    {
        $query = SiteOperationHistory::query();
        if ($since) {
            $query->where('started_at', '>=', $since);
        }
        $items = $query->get();
        $successful = $items->where('status', 'succeeded')->count();
        $average = $items->isEmpty() ? 0 : $items->avg(fn (SiteOperationHistory $item): int => max(0, $item->completed_at->diffInMilliseconds($item->started_at)));

        return [
            'total' => $items->count(),
            'successful' => $successful,
            'failed' => $items->count() - $successful,
            'average_duration_ms' => (int) round($average),
            'site_count' => $items->pluck('site_id')->unique()->count(),
            'last_operation_at' => $items->max('started_at')?->toIso8601String(),
        ];
    }

    public function getStorageInfo(): array
    {
        $query = SiteOperationHistory::query();

        return [
            'record_count' => $query->count(),
            'oldest_operation_at' => $query->min('started_at'),
            'newest_operation_at' => $query->max('started_at'),
            'site_count' => $query->distinct()->count('site_id'),
            'storage' => 'database',
        ];
    }

    public function previewCleanup(int $olderThanDays, int $keepLatest = 100): array
    {
        $days = max(1, min(3650, $olderThanDays));
        $keep = max(0, min(self::MAX_RECORDS, $keepLatest));
        $cutoff = now()->subDays($days);
        $protected = SiteOperationHistory::query()->latest('started_at')->limit($keep)->pluck('id');
        $removable = SiteOperationHistory::query()->where('started_at', '<', $cutoff)->whereNotIn('id', $protected);

        return [
            'total_count' => SiteOperationHistory::query()->count(),
            'removable_count' => $removable->count(),
            'cutoff' => $cutoff->toIso8601String(),
            'keep_latest' => $keep,
        ];
    }

    public function cleanup(int $olderThanDays, int $keepLatest = 100): array
    {
        return DB::transaction(function () use ($olderThanDays, $keepLatest): array {
            $preview = $this->previewCleanup($olderThanDays, $keepLatest);
            $protected = SiteOperationHistory::query()->latest('started_at')->limit($preview['keep_latest'])->pluck('id');
            $removed = SiteOperationHistory::query()->where('started_at', '<', $preview['cutoff'])->whereNotIn('id', $protected)->delete();

            return ['removed_count' => $removed, 'remaining_count' => SiteOperationHistory::query()->count(), 'cutoff' => $preview['cutoff']];
        }, 3);
    }

    public function clear(int $siteId): int
    {
        return SiteOperationHistory::query()->where('site_id', $siteId)->delete();
    }

    public function record(int $siteId, string $operation, bool $succeeded, string $message, array $details = [], ?int $affectedRecords = null, ?string $correlationId = null, ?\DateTimeInterface $startedAt = null): SiteOperationHistory
    {
        $record = SiteOperationHistory::query()->create([
            'site_id' => $siteId,
            'correlation_id' => $correlationId ?: (string) Str::uuid(),
            'operation' => $operation,
            'status' => $succeeded ? 'succeeded' : 'failed',
            'message' => $message,
            'details' => $this->redact($details),
            'affected_records' => $affectedRecords,
            'started_at' => $startedAt ?? now(),
            'completed_at' => now(),
        ]);
        $protected = SiteOperationHistory::query()->latest('started_at')->limit(self::MAX_RECORDS)->pluck('id');
        if ($protected->isNotEmpty()) {
            SiteOperationHistory::query()->whereNotIn('id', $protected)->delete();
        }

        return $record;
    }

    /**
     * Site diagnostics is a read-only support API. Historic sync failure records
     * predate structured error classification and may contain credentials in
     * either their message or arbitrary details. Never serialize those raw fields.
     * Preserve the original database row for authorized forensic workflows.
     */
    private function safeSiteItem(SiteOperationHistory $item): array
    {
        // Eloquent toArray() also exposes arbitrary legacy details and future
        // model columns. Site history is user-facing, not a forensic raw dump.
        $data = array_intersect_key($item->toArray(), array_fill_keys([
            'id', 'site_id', 'correlation_id', 'operation', 'status',
            'message', 'details', 'affected_records', 'started_at', 'completed_at', 'site',
        ], true));
        $operation = (string) ($data['operation'] ?? '');
        $data['operation'] = preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $operation) === 1
            ? $operation : 'operation_withheld';
        $data['correlation_id'] = Str::isUuid((string) ($data['correlation_id'] ?? ''))
            ? $data['correlation_id'] : null;

        if ($item->operation !== 'synchronization' || $item->status !== 'failed') {
            // Do not reflect raw success, diagnostic or legacy error messages:
            // they can contain URLs, credentials, stack traces and nested data.
            $data['message'] = match ($item->status) {
                'succeeded' => match ($item->operation) {
                    'synchronization' => 'WordPress synchronization completed.',
                    'diagnostic_recheck' => 'Site diagnostic recheck completed.',
                    'reconnect' => 'Connector reconnect requested.',
                    'disconnect' => 'Connector disconnected.',
                    default => 'Site operation completed.',
                },
                'failed' => 'Site operation failed. Review authorized diagnostics.',
                default => 'Site operation state recorded.',
            };
            $data['details'] = [];

            return $data;
        }

        $data['message'] = 'WordPress synchronization failed.';
        $details = is_array($item->details) ? $item->details : [];
        $guidance = [
            'authentication' => 'WordPress rejected the connection. Recheck Connector credentials and signed request permissions.',
            'rate_limit' => 'WordPress or a provider reported a limit. Check quotas and retry when permitted.',
            'protocol' => 'The Connector protocol was not accepted. Verify both installed Connector and Laravel versions.',
            'transport' => 'The WordPress endpoint could not be reached reliably. Check network and TLS connectivity.',
            'remote_service' => 'WordPress returned a server-side error. Check the remote service health before retrying.',
            'storage' => 'Synchronization encountered a database or storage failure. Check server health and free capacity.',
            'unknown' => 'Synchronization failed for an unclassified reason. Open the authorized run details for diagnosis.',
        ];
        $category = $details['failure_category'] ?? null;
        $code = is_string($category) && isset($guidance[$category]) ? 'WP_SYNC_'.strtoupper($category) : null;
        $safeDetails = [];
        if ($code !== null && ($details['failure_code'] ?? null) === $code) {
            $safeDetails['failure_code'] = $code;
            $safeDetails['failure_category'] = $category;
            $safeDetails['failure_message'] = $guidance[$category];
        }
        $runId = $details['sync_run_id'] ?? null;
        if (is_int($runId) && $runId > 0) {
            $safeDetails['sync_run_id'] = $runId;
        }

        $data['details'] = $safeDetails;

        return $data;
    }

    private function redact(array $value): array
    {
        $redacted = [];
        foreach ($value as $key => $item) {
            if (preg_match('/secret|token|password|authorization|cookie|api.?key/i', (string) $key)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $redacted[$key] = $this->redact($item);
            } else {
                $redacted[$key] = $item;
            }
        }

        return $redacted;
    }
}
