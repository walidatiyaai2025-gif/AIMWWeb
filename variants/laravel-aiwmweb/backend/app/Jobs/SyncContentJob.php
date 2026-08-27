<?php

namespace App\Jobs;

use App\Content\ContentPlatformService;

final class SyncContentJob extends TenantAwareJob
{
    public int $tries = 4;
    public array $backoff = [30, 120, 300];

    public function __construct(int $tenantId, public readonly int $siteId, public readonly bool $full = false) { parent::__construct($tenantId); }
    public function uniqueId(): string { return "tenant:{$this->tenantId}:site:{$this->siteId}:content-sync"; }
    public function handle(ContentPlatformService $service): void { $service->sync($this->siteId, $this->full); }
}
