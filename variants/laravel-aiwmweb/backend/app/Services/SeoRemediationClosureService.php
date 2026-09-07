<?php

namespace App\Services;

use App\Connector\WordPressGateway;
use App\Jobs\ExecuteApprovedSuggestionJob;
use App\Models\Approval;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoRemediationClosureService
{
    private const PLUGIN_FIELDS = ['seo_title', 'seo_description', 'seo_canonical', 'seo_robots'];

    public function __construct(
        private readonly WordPressGateway $wordpress,
        private readonly SeoManagerService $seo,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function proposals(int $siteId): array
    {
        $suggestions = Suggestion::query()->where('site_id', $siteId)->latest()->get();
        $approvals = Approval::query()->whereIn('suggestion_id', $suggestions->pluck('id'))->get()->keyBy('suggestion_id');
        $executions = Execution::query()->whereIn('approval_id', $approvals->pluck('id'))->get()->keyBy('approval_id');

        return $suggestions->map(function (Suggestion $suggestion) use ($approvals, $executions): array {
            $approval = $approvals->get($suggestion->id);
            $execution = $approval ? $executions->get($approval->id) : null;

            return [
                'suggestion_id' => $suggestion->id,
                'site_id' => $suggestion->site_id,
                'finding_id' => $suggestion->seo_finding_id,
                'content_id' => $suggestion->synced_content_id,
                'status' => $suggestion->status,
                'before_state' => $suggestion->before_state,
                'proposed_state' => $suggestion->proposed_state,
                'approval' => $approval ? [
                    'id' => $approval->id,
                    'status' => $approval->status,
                    'decided_at' => $approval->decided_at,
                ] : null,
                'execution' => $execution ? [
                    'id' => $execution->id,
                    'status' => $execution->status,
                    'operation_id' => $execution->operation_id,
                    'attempts' => $execution->attempts,
                ] : null,
                'created_at' => $suggestion->created_at,
            ];
        })->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $siteId): array
    {
        $executions = Execution::query()->where('site_id', $siteId)->latest()->get();
        $receipts = EvidenceReceipt::query()->whereIn('execution_id', $executions->pluck('id'))->get()->keyBy('execution_id');

        return $executions->map(function (Execution $execution) use ($receipts): array {
            $receipt = $receipts->get($execution->id);

            return [
                'execution_id' => $execution->id,
                'site_id' => $execution->site_id,
                'approval_id' => $execution->approval_id,
                'operation_id' => $execution->operation_id,
                'request_id' => $execution->request_id,
                'correlation_id' => $execution->correlation_id,
                'status' => $execution->status,
                'attempts' => $execution->attempts,
                'failure' => $execution->failure,
                'started_at' => $execution->started_at,
                'completed_at' => $execution->completed_at,
                'receipt' => $receipt ? [
                    'verified' => $receipt->verified,
                    'before_state' => $receipt->before_state,
                    'proposed_state' => $receipt->proposed_state,
                    'actual_after_state' => $receipt->actual_after_state,
                    'failure' => $receipt->failure,
                    'created_at' => $receipt->created_at,
                ] : null,
            ];
        })->all();
    }

    /** @return array{queued:int,execution_ids:array<int,int>,mutated:bool} */
    public function retryFailed(Site $site): array
    {
        $executionIds = DB::transaction(function () use ($site): array {
            $failed = Execution::query()
                ->where('site_id', $site->id)
                ->where('status', 'failed')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($failed->isEmpty()) {
                return [];
            }

            $approvals = Approval::query()
                ->whereIn('id', $failed->pluck('approval_id'))
                ->get()
                ->keyBy('id');
            $queued = [];

            foreach ($failed as $execution) {
                $approval = $approvals->get($execution->approval_id);
                $proposedState = $approval?->proposed_state;
                if (! $approval || $approval->status !== 'APPROVED' || ! is_array($proposedState) || $proposedState === []) {
                    continue;
                }

                $claimed = Execution::query()
                    ->whereKey($execution->id)
                    ->where('site_id', $site->id)
                    ->where('status', 'failed')
                    ->update([
                        'status' => 'queued',
                        'started_at' => null,
                        'completed_at' => null,
                        'failure' => null,
                    ]);

                if ($claimed === 1) {
                    $queued[] = (int) $execution->id;
                }
            }

            return $queued;
        });

        foreach ($executionIds as $executionId) {
            ExecuteApprovedSuggestionJob::dispatch((int) $site->tenant_id, $executionId);
        }

        return [
            'queued' => count($executionIds),
            'execution_ids' => $executionIds,
            'mutated' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function prepareUndo(Site $site, Execution $execution, int $actorUserId): array
    {
        if ((int) $execution->site_id !== (int) $site->id) {
            throw new RuntimeException('Undo execution does not belong to the requested site.');
        }

        $receipt = EvidenceReceipt::query()
            ->where('site_id', $site->id)
            ->where('execution_id', $execution->id)
            ->where('verified', true)
            ->first();
        if (! $receipt || ! is_array($receipt->actual_after_state)) {
            throw new RuntimeException('Undo requires a verified evidence receipt.');
        }

        $approval = Approval::query()->findOrFail($execution->approval_id);
        $suggestion = Suggestion::query()->where('site_id', $site->id)->findOrFail($approval->suggestion_id);
        $content = SyncedContent::query()->where('site_id', $site->id)->findOrFail($suggestion->synced_content_id);

        $authoritative = $this->wordpress->read($site, $content->resource_type, $content->remote_id);
        if (! $this->seo->statesMatch((array) $receipt->actual_after_state, $authoritative)) {
            throw new RuntimeException('UNDO_STALE_WORDPRESS_STATE: authoritative state changed after the verified execution.');
        }

        $originalFields = array_values(array_intersect(SeoManagerService::WRITABLE_FIELDS, array_keys((array) $approval->proposed_state)));
        if ($originalFields === []) {
            throw new RuntimeException('Undo receipt contains no reversible SEO fields.');
        }

        $beforeMutation = $this->seo->metadata((array) $receipt->before_state);
        $revert = Arr::only($beforeMutation, $originalFields);
        if (count($revert) !== count($originalFields)) {
            throw new RuntimeException('Undo receipt is missing the authoritative pre-mutation values.');
        }

        $currentMetadata = $this->seo->metadata($authoritative);
        $provider = $this->seo->providerState(
            $currentMetadata['seo_provider'],
            $this->nullableBool($authoritative['seo_provider_enabled'] ?? null),
            $this->nullableBool($authoritative['seo_provider_available'] ?? null),
        );
        if (array_intersect(self::PLUGIN_FIELDS, $originalFields) !== [] && $provider['state'] !== 'SUPPORTED_ENABLED') {
            throw new RuntimeException('Undo is unavailable because the detected SEO provider is not enabled and available.');
        }

        return DB::transaction(function () use ($site, $suggestion, $content, $actorUserId, $currentMetadata, $revert, $execution): array {
            $undoSuggestion = Suggestion::query()->create([
                'site_id' => $site->id,
                'seo_finding_id' => $suggestion->seo_finding_id,
                'synced_content_id' => $content->id,
                'actor_user_id' => $actorUserId,
                'status' => 'awaiting_approval',
                'before_state' => $currentMetadata,
                'proposed_state' => $revert,
            ]);
            $undoApproval = Approval::query()->create([
                'suggestion_id' => $undoSuggestion->id,
                'actor_user_id' => $actorUserId,
                'status' => 'PENDING',
                'before_state' => $currentMetadata,
                'proposed_state' => $revert,
            ]);

            return [
                'undo_of_execution_id' => $execution->id,
                'suggestion' => $undoSuggestion,
                'approval' => $undoApproval,
                'requires_approval' => true,
                'mutated' => false,
            ];
        });
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'enabled', 'available' => true,
                '0', 'false', 'no', 'disabled', 'unavailable' => false,
                default => null,
            };
        }

        return null;
    }
}
