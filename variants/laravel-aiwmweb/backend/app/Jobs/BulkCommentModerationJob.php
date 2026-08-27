<?php

namespace App\Jobs;

use App\Content\ContentPlatformService;
use App\Models\Comment;

final class BulkCommentModerationJob extends TenantAwareJob
{
    public int $tries = 3;
    public function __construct(int $tenantId, public readonly int $siteId, public readonly array $commentIds, public readonly string $action) { parent::__construct($tenantId); }
    public function uniqueId(): string { return "tenant:{$this->tenantId}:site:{$this->siteId}:comments:".hash('sha256', json_encode([$this->commentIds,$this->action])); }

    public function handle(ContentPlatformService $service): void
    {
        $comments = Comment::query()->where('site_id',$this->siteId)->whereIn('id',$this->commentIds)->get();
        abort_unless($comments->count() === count(array_unique($this->commentIds)), 422, 'Bulk selection includes unavailable comments.');
        foreach ($comments as $comment) $service->mutateComment($this->siteId, $comment->remote_id, $this->action);
    }
}
