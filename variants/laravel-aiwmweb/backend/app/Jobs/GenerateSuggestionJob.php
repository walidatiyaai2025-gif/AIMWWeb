<?php

namespace App\Jobs;

use App\AI\AiProvider;
use App\AI\Durable\BatchService;
use App\AI\Durable\ExecutionPermit;
use App\Models\AiProviderConfig;
use App\Models\Approval;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Throwable;

final class GenerateSuggestionJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $suggestionId, public readonly ?array $snapshot = null)
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
        if (config('cpanel.enabled') && ! app(ExecutionPermit::class)->active()) {
            if ($suggestion->status === 'ready') {
                return;
            }
            $context = app(TenantContext::class);
            $membership = TenantMembership::query()->where('user_id', $suggestion->actor_user_id)->where('status', 'active')->firstOrFail();
            $context->activate($context->tenant(), $membership);
            $config = AiProviderConfig::query()->where('enabled', true)->firstOrFail();
            $finding = SeoFinding::query()->findOrFail($suggestion->seo_finding_id);
            $content = SyncedContent::query()->findOrFail($suggestion->synced_content_id);
            abort_unless((int) $content->site_id === (int) $suggestion->site_id && (int) $finding->synced_content_id === (int) $content->id && (int) SeoAudit::query()->findOrFail($finding->seo_audit_id)->site_id === (int) $suggestion->site_id, 404);
            $snapshot = ['config_id' => $config->id, 'model' => $config->model, 'endpoint_hash' => hash('sha256', $config->endpoint),
                'content' => $content->only(['resource_type', 'remote_id', 'slug', 'title', 'content', 'seo_title', 'seo_description']),
                'finding' => $finding->only(['code', 'severity', 'recommendation'])];
            app(BatchService::class)->submit([
                ['key' => 'suggestion', 'request' => ['_legacy_suggestion_id' => $suggestion->id, '_legacy_snapshot' => $snapshot, 'max_output_tokens' => 1500]],
            ], (int) $suggestion->site_id, 'legacy-suggestion:'.$suggestion->id);

            return;
        }
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
            $config = $this->snapshot === null
                ? AiProviderConfig::query()->where('enabled', true)->firstOrFail()
                : AiProviderConfig::query()->where('enabled', true)->findOrFail($this->snapshot['config_id']);
            if ($this->snapshot !== null && ($config->model !== $this->snapshot['model'] || ! hash_equals($this->snapshot['endpoint_hash'], hash('sha256', $config->endpoint)))) {
                throw new \RuntimeException('Provider configuration changed; review the saved task before execution.');
            }
            $finding = SeoFinding::query()->findOrFail($suggestion->seo_finding_id);
            $content = SyncedContent::query()->findOrFail($suggestion->synced_content_id);
            $proposed = $provider->suggest($config, $this->snapshot['content'] ?? $content->only(['resource_type', 'remote_id', 'slug', 'title', 'content', 'seo_title', 'seo_description']), $this->snapshot['finding'] ?? $finding->only(['code', 'severity', 'recommendation']));
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
