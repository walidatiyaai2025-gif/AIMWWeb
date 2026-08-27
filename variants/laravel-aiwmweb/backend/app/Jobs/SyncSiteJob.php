<?php

namespace App\Jobs;

use App\Connector\WordPressGateway;
use App\Models\Site;
use App\Models\SyncedContent;
use App\Models\SyncRun;
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

    public function handle(WordPressGateway $wordpress): void
    {
        $run = SyncRun::query()->findOrFail($this->syncRunId);
        $site = Site::query()->findOrFail($this->siteId);
        $run->update(['status' => 'running', 'started_at' => now(), 'failure' => null]);
        try {
            $payload = $wordpress->content($site, $site->last_sync_at?->toIso8601String());
            $items = $payload['items'] ?? [];
            foreach ($items as $item) {
                SyncedContent::query()->updateOrCreate(['site_id' => $site->id, 'resource_type' => $item['type'], 'remote_id' => $item['id']], ['slug' => $item['slug'], 'title' => $item['title'] ?? null, 'content' => $item['content'] ?? null, 'excerpt' => $item['excerpt'] ?? null, 'headings' => $item['headings'] ?? [], 'taxonomy' => $item['taxonomy'] ?? [], 'media' => $item['media'] ?? [], 'seo_title' => $item['seo_title'] ?? null, 'seo_description' => $item['seo_description'] ?? null, 'remote_modified_at' => $item['modified_at'] ?? now()]);
            }
            $site->update(['last_sync_at' => now(), 'health_state' => 'healthy']);
            $run->update(['status' => 'succeeded', 'processed' => count($items), 'completed_at' => now()]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'failure' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }
    }
}
