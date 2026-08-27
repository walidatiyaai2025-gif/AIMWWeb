<?php

namespace App\Jobs;

use App\Connector\WordPressGateway;
use App\Models\Site;
use App\Models\SyncedContent;
use App\Models\SyncRun;
use App\Services\SeoManagerService;
use Throwable;

final class SyncSiteJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $siteId, public readonly int $syncRunId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:site:{$this->siteId}:sync";
    }

    public function handle(WordPressGateway $wordpress, SeoManagerService $seo): void
    {
        $run = SyncRun::query()->findOrFail($this->syncRunId);
        $site = Site::query()->findOrFail($this->siteId);
        $run->update(['status' => 'running', 'started_at' => now(), 'failure' => null]);
        try {
            $payload = $wordpress->content($site, $site->last_sync_at?->toIso8601String());
            $items = $payload['items'] ?? [];
            foreach ($items as $item) {
                $metadata = $seo->metadata($item);
                SyncedContent::query()->updateOrCreate(
                    ['site_id' => $site->id, 'resource_type' => $item['type'], 'remote_id' => $item['id']],
                    [
                        'slug' => $item['slug'],
                        'title' => $item['title'] ?? null,
                        'content' => $item['content'] ?? null,
                        'excerpt' => $item['excerpt'] ?? null,
                        'headings' => $item['headings'] ?? [],
                        'taxonomy' => $item['taxonomy'] ?? [],
                        'media' => $item['media'] ?? [],
                        'seo_title' => $metadata['seo_title'],
                        'seo_description' => $metadata['seo_description'],
                        'seo_provider' => $metadata['seo_provider'],
                        'seo_canonical' => $metadata['seo_canonical'],
                        'seo_robots' => $metadata['seo_robots'],
                        'seo_readability_score' => $seo->readabilityScore((string) ($item['content'] ?? '')),
                        'seo_source_hash' => $seo->sourceHash($metadata),
                        'remote_modified_at' => $item['modified_at'] ?? now(),
                    ]
                );
            }
            $site->update(['last_sync_at' => now(), 'health_state' => 'healthy']);
            $run->update(['status' => 'succeeded', 'processed' => count($items), 'completed_at' => now()]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'failure' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }
    }
}
