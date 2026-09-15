<?php

namespace App\Jobs;

use App\AI\AiProvider;
use App\Models\AiProviderConfig;
use App\Models\Approval;
use App\Models\SeoFinding;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use Throwable;

final class GenerateSuggestionJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $suggestionId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:suggestion:{$this->suggestionId}";
    }

    public function handle(AiProvider $provider, JobFailureGate $failureGate): void
    {
        $suggestion = Suggestion::query()->findOrFail($this->suggestionId);
        $gate = $failureGate->canStart((int) $suggestion->site_id, self::class);
        if (! $gate->canRun) {
            $delaySeconds = $gate->resumeAtUtc === null
                ? 60
                : max(60, now('UTC')->diffInSeconds($gate->resumeAtUtc, false));
            $this->release($delaySeconds);

            return;
        }

        $suggestion->update(['status' => 'running', 'failure' => null]);
        try {
            $config = AiProviderConfig::query()->where('enabled', true)->firstOrFail();
            $finding = SeoFinding::query()->findOrFail($suggestion->seo_finding_id);
            $content = SyncedContent::query()->findOrFail($suggestion->synced_content_id);
            $proposed = $provider->suggest($config, $content->only(['resource_type', 'remote_id', 'slug', 'title', 'content', 'seo_title', 'seo_description']), $finding->only(['code', 'severity', 'recommendation']));
            $allowed = array_intersect_key($proposed, array_flip(['title', 'content', 'slug', 'seo_title', 'seo_description']));
            if ($allowed === []) {
                throw new \RuntimeException('AI suggestion contains no allowed semantic changes.');
            }
            $suggestion->update(['status' => 'ready', 'proposed_state' => $allowed]);
            Approval::query()->create(['suggestion_id' => $suggestion->id, 'actor_user_id' => $suggestion->actor_user_id, 'status' => 'PENDING', 'before_state' => $suggestion->before_state, 'proposed_state' => $allowed]);
        } catch (Throwable $e) {
            $suggestion->update(['status' => 'failed', 'failure' => $e->getMessage()]);
            throw $e;
        }
    }
}
