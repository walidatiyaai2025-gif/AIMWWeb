<?php

namespace App\Jobs;

use App\Connector\WordPressGateway;
use App\Models\Approval;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
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

    public function handle(WordPressGateway $wordpress): void
    {
        $execution = Execution::query()->findOrFail($this->executionId);
        $claimed = Execution::query()->whereKey($execution->id)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(), 'attempts' => $execution->attempts + 1, 'failure' => null,
        ]);
        if (! $claimed) {
            return;
        }
        $execution->refresh();
        $approval = Approval::query()->findOrFail($execution->approval_id);
        if ($approval->status !== 'APPROVED') {
            throw new \RuntimeException('Execution requires an approved change.');
        }
        $suggestion = Suggestion::query()->findOrFail($approval->suggestion_id);
        $content = SyncedContent::query()->findOrFail($suggestion->synced_content_id);
        $site = Site::query()->findOrFail($execution->site_id);
        try {
            $change = ['resource_type' => $content->resource_type, 'remote_id' => $content->remote_id, 'changes' => $approval->proposed_state];
            $wordpress->execute($site, $execution->operation_id, $change);
            $actual = $wordpress->read($site, $content->resource_type, $content->remote_id);
            $verified = collect($approval->proposed_state)->every(fn ($value, $key) => data_get($actual, $key) === $value);
            if (! $verified) {
                throw new \RuntimeException('WordPress re-read did not match the approved proposed state.');
            }
            $content->update(array_intersect_key($actual, array_flip(['slug', 'title', 'content', 'seo_title', 'seo_description'])));
            EvidenceReceipt::query()->create(['site_id' => $site->id, 'execution_id' => $execution->id, 'actor_user_id' => $execution->actor_user_id, 'operation_id' => $execution->operation_id, 'request_id' => $execution->request_id, 'correlation_id' => $execution->correlation_id, 'before_state' => $approval->before_state, 'proposed_state' => $approval->proposed_state, 'actual_after_state' => $actual, 'verified' => true]);
            $execution->update(['status' => 'succeeded', 'completed_at' => now()]);
        } catch (Throwable $e) {
            EvidenceReceipt::query()->firstOrCreate(['execution_id' => $execution->id], ['site_id' => $site->id, 'actor_user_id' => $execution->actor_user_id, 'operation_id' => $execution->operation_id, 'request_id' => $execution->request_id, 'correlation_id' => $execution->correlation_id, 'before_state' => $approval->before_state, 'proposed_state' => $approval->proposed_state, 'verified' => false, 'failure' => $e->getMessage()]);
            $execution->update(['status' => 'failed', 'failure' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }
    }
}
