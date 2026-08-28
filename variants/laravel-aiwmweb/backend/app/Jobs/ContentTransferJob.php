<?php

namespace App\Jobs;

use App\Content\ContentPlatformService;
use App\Models\ContentItem;
use App\Models\ContentTransfer;
use App\Models\MediaItem;
use App\Models\TaxonomyTerm;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ContentTransferJob extends TenantAwareJob
{
    public int $tries = 2;

    public function __construct(int $tenantId, public readonly int $transferId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:content-transfer:{$this->transferId}";
    }

    public function handle(ContentPlatformService $service): void
    {
        $transfer = ContentTransfer::query()->findOrFail($this->transferId);
        $transfer->update(['state' => 'running', 'progress' => 5, 'started_at' => now(), 'last_error' => null]);
        try {
            if ($transfer->kind === 'export') {
                $payload = ['version' => 1, 'site_id' => $transfer->site_id, 'exported_at' => now()->toIso8601String(), 'content' => ContentItem::query()->where('site_id', $transfer->site_id)->get()->toArray(), 'media' => MediaItem::query()->where('site_id', $transfer->site_id)->get()->toArray(), 'taxonomy' => TaxonomyTerm::query()->where('site_id', $transfer->site_id)->get()->toArray()];
                $path = "content-transfers/{$this->tenantId}/{$transfer->site_id}/{$transfer->id}.json";
                Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $transfer->update(['state' => 'completed', 'progress' => 100, 'storage_path' => $path, 'result' => ['content' => count($payload['content']), 'media' => count($payload['media']), 'taxonomy' => count($payload['taxonomy'])], 'completed_at' => now()]);

                return;
            }
            if (! $transfer->storage_path || ! Storage::disk('local')->exists($transfer->storage_path)) {
                throw new RuntimeException('Import source is unavailable.');
            }
            $payload = json_decode(Storage::disk('local')->get($transfer->storage_path), true, 512, JSON_THROW_ON_ERROR);
            if (($payload['version'] ?? null) !== 1 || ! is_array($payload['content'] ?? null)) {
                throw new RuntimeException('Unsupported or invalid content import format.');
            }
            $rows = $payload['content'];
            $total = max(count($rows), 1);
            $done = 0;
            foreach ($rows as $row) {
                if (! in_array($row['type'] ?? null, ['post', 'page'], true)) {
                    continue;
                }
                $service->mutateContent($transfer->site_id, $row['type'], null, 'create', array_filter(['title' => $row['title'] ?? null, 'slug' => $row['slug'] ?? null, 'content' => $row['body'] ?? null, 'excerpt' => $row['excerpt'] ?? null, 'status' => $row['status'] ?? 'draft']));
                $done++;
                $transfer->update(['progress' => min(99, 5 + (int) floor(($done / $total) * 90))]);
            }
            $transfer->update(['state' => 'completed', 'progress' => 100, 'result' => ['imported' => $done], 'completed_at' => now()]);
        } catch (\Throwable $e) {
            $transfer->update(['state' => 'failed', 'last_error' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }
    }
}
