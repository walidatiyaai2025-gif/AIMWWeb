<?php

namespace App\Jobs;

use App\Content\ContentPlatformService;
use App\Models\ContentItem;

final class BulkContentMutationJob extends TenantAwareJob
{
    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(int $tenantId, public readonly int $siteId, public readonly array $contentIds, public readonly string $action, public readonly array $payload = [])
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:site:{$this->siteId}:bulk:".hash('sha256', json_encode([$this->contentIds, $this->action]));
    }

    public function handle(ContentPlatformService $service): void
    {
        $items = ContentItem::query()->where('site_id', $this->siteId)->whereIn('id', $this->contentIds)->get();
        abort_unless($items->count() === count(array_unique($this->contentIds)), 422, 'Bulk selection includes unavailable content.');
        foreach ($items as $item) {
            $service->mutateContent($this->siteId, $item->type, $item->remote_id, $this->action, $this->payload, ['hash' => $item->remote_hash, 'modified_at' => $item->remote_modified_at?->toIso8601String()]);
        }
    }
}
