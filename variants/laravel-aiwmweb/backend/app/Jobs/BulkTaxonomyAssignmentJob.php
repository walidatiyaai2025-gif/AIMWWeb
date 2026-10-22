<?php

namespace App\Jobs;

use App\Content\ContentPlatformService;
use App\Models\ContentItem;

final class BulkTaxonomyAssignmentJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $siteId, public readonly array $contentIds, public readonly array $termIds)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:site:{$this->siteId}:taxonomy:".hash('sha256', json_encode([$this->contentIds, $this->termIds]));
    }

    public function handle(ContentPlatformService $service): void
    {
        $items = ContentItem::query()->where('site_id', $this->siteId)->whereIn('id', $this->contentIds)->get();
        abort_unless($items->count() === count(array_unique($this->contentIds)), 422, 'Bulk selection includes unavailable content.');
        foreach ($items as $item) {
            $service->assignTerms($this->siteId, $item, $this->termIds);
        }
    }
}
