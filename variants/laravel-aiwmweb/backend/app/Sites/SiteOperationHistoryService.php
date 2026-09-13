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
        return SiteOperationHistory::query()->where('site_id', $siteId)->latest('started_at')->limit(max(1, min(500, $take)))->get()->toArray();
    }

    public function getAll(int $take = 250): array
    {
        return SiteOperationHistory::query()->latest('started_at')->limit(max(1, min(self::MAX_RECORDS, $take)))->get()->toArray();
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
