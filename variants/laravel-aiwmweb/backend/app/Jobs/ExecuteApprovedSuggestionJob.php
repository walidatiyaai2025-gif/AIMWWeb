<?php

namespace App\Jobs;

use App\Connector\WordPressGateway;
use App\Models\Approval;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Services\SeoManagerService;
use RuntimeException;
use Throwable;

final class ExecuteApprovedSuggestionJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $executionId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:execution:{$this->executionId}";
    }

    public function handle(WordPressGateway $wordpress, SeoManagerService $seo): void
    {
        $execution = Execution::query()->findOrFail($this->executionId);
        $claimed = Execution::query()->whereKey($execution->id)->where('status', 'queued')->update([
            'status' => 'running',
            'started_at' => now(),
            'completed_at' => null,
            'attempts' => $execution->attempts + 1,
            'failure' => null,
        ]);
        if (! $claimed) {
            return;
        }
        $execution->refresh();
        $approval = Approval::query()->findOrFail($execution->approval_id);
        if ($approval->status !== 'APPROVED') {
            throw new RuntimeException('Execution requires an approved change.');
        }
        $suggestion = Suggestion::query()->findOrFail($approval->suggestion_id);
        $content = SyncedContent::query()->findOrFail($suggestion->synced_content_id);
        $site = Site::query()->findOrFail($execution->site_id);

        try {
            $authoritativeBefore = $wordpress->read($site, $content->resource_type, $content->remote_id);
            if (! $seo->statesMatch((array) $approval->before_state, $authoritativeBefore)) {
                throw new RuntimeException('STALE_WORDPRESS_STATE: authoritative state changed after approval preparation.');
            }

            $change = [
                'resource_type' => $content->resource_type,
                'remote_id' => $content->remote_id,
                'changes' => $approval->proposed_state,
            ];
            $wordpress->execute($site, $execution->operation_id, $change);
            $actual = $wordpress->read($site, $content->resource_type, $content->remote_id);
            if (! $seo->proposedStateVerified((array) $approval->proposed_state, $actual)) {
                throw new RuntimeException('WORDPRESS_REREAD_MISMATCH: approved SEO state was not observed after mutation.');
            }

            $metadata = $seo->metadata($actual);
            $content->update([
                'slug' => $metadata['slug'],
                'title' => $metadata['title'],
                'seo_title' => $metadata['seo_title'],
                'seo_description' => $metadata['seo_description'],
                'seo_provider' => $metadata['seo_provider'],
                'seo_canonical' => $metadata['seo_canonical'],
                'seo_robots' => $metadata['seo_robots'],
                'seo_readability_score' => $seo->readabilityScore((string) ($actual['content'] ?? $content->content)),
                'seo_source_hash' => $seo->sourceHash($metadata),
                'remote_modified_at' => $actual['modified_at'] ?? $content->remote_modified_at,
            ]);
            EvidenceReceipt::query()->updateOrCreate(
                ['execution_id' => $execution->id],
                [
                    'site_id' => $site->id,
                    'actor_user_id' => $execution->actor_user_id,
                    'operation_id' => $execution->operation_id,
                    'request_id' => $execution->request_id,
                    'correlation_id' => $execution->correlation_id,
                    'before_state' => $authoritativeBefore,
                    'proposed_state' => $approval->proposed_state,
                    'actual_after_state' => $actual,
                    'verified' => true,
                    'failure' => null,
                ]
            );
            $execution->update(['status' => 'succeeded', 'completed_at' => now(), 'failure' => null]);
        } catch (Throwable $e) {
            EvidenceReceipt::query()->updateOrCreate(
                ['execution_id' => $execution->id],
                [
                    'site_id' => $site->id,
                    'actor_user_id' => $execution->actor_user_id,
                    'operation_id' => $execution->operation_id,
                    'request_id' => $execution->request_id,
                    'correlation_id' => $execution->correlation_id,
                    'before_state' => $approval->before_state,
                    'proposed_state' => $approval->proposed_state,
                    'actual_after_state' => null,
                    'verified' => false,
                    'failure' => $e->getMessage(),
                ]
            );
            $execution->update(['status' => 'failed', 'failure' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }
    }
}
