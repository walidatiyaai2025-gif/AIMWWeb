<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Contracts\PlannerApprovalGateway;
use App\AI\Platform\Contracts\PlannerSiteGateway;
use App\Models\AiPlannerHistory;
use App\Models\AiPlannerItem;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class AiContentPlannerService
{
    public const STATUSES = [
        'idea',
        'brief',
        'draft',
        'review',
        'scheduled',
        'published',
        'cancelled',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AiGenerator $generator,
        private readonly PlannerApprovalGateway $approval,
        private readonly PlannerSiteGateway $sites,
        private readonly AuditLogger $audit,
    ) {}

    public function list(array $filters = []): array
    {
        return $this->ownedQuery()
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['site_id'] ?? null, fn ($query, $value) => $query->where('site_id', $value))
            ->when(trim((string) ($filters['search'] ?? '')), function ($query, $value): void {
                $search = trim((string) $value);
                $query->where(fn ($nested) => $nested
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('idea', 'like', "%{$search}%"));
            })
            ->latest('updated_at')
            ->get()
            ->map(fn (AiPlannerItem $item) => $this->serialize($item))
            ->all();
    }

    public function get(int $id): AiPlannerItem
    {
        return $this->ownedQuery()->findOrFail($id);
    }

    public function create(array $input): AiPlannerItem
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Planner title is required.']);
        }

        $siteId = isset($input['site_id']) ? (int) $input['site_id'] : null;
        $this->sites->assertOwned($siteId);

        $item = AiPlannerItem::query()->create([
            'user_id' => $this->context->membership()->user_id,
            'site_id' => $siteId,
            'title' => $title,
            'idea' => trim((string) ($input['idea'] ?? '')) ?: null,
            'keywords' => array_values(array_filter(array_map('strval', (array) ($input['keywords'] ?? [])))),
            'topics' => array_values(array_filter(array_map('strval', (array) ($input['topics'] ?? [])))),
            'status' => 'idea',
            'scheduled_at' => $input['scheduled_at'] ?? null,
            'version' => 1,
        ]);
        $this->history($item, 'created');
        $this->audit->record('ai.planner.created', [
            'status' => $item->status,
            'site_id' => $item->site_id,
        ], 'AiPlannerItem', $item->id);

        return $item;
    }

    public function update(AiPlannerItem $item, array $input): AiPlannerItem
    {
        $this->assertOwnedItem($item);
        $siteId = array_key_exists('site_id', $input)
            ? ($input['site_id'] === null ? null : (int) $input['site_id'])
            : $item->site_id;
        $this->sites->assertOwned($siteId);

        if (isset($input['status'])) {
            $status = (string) $input['status'];
            if (! in_array($status, ['idea', 'brief', 'draft', 'review', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Planner cannot directly set a published or scheduled terminal state.',
                ]);
            }
        }

        $item->fill(Arr::only($input, [
            'title',
            'idea',
            'keywords',
            'topics',
            'scheduled_at',
            'status',
        ]));
        $item->site_id = $siteId;
        $item->version++;
        $item->save();
        $this->history($item, 'updated');
        $this->audit->record('ai.planner.updated', [
            'status' => $item->status,
            'version' => $item->version,
        ], 'AiPlannerItem', $item->id);

        return $item->fresh();
    }

    public function generateBrief(AiPlannerItem $item): AiPlannerItem
    {
        $this->assertOwnedItem($item);
        $result = $this->generator->generate([
            'workflow' => 'content-planner.brief',
            'prompt_key' => 'content.brief',
            'variables' => [
                'title' => $item->title,
                'idea' => $item->idea ?? $item->title,
                'keywords' => $item->keywords ?? [],
                'topics' => $item->topics ?? [],
            ],
            'site_id' => $item->site_id,
            'max_output_tokens' => 1800,
        ]);

        $structured = $result['structured'] ?? null;
        if (! is_array($structured) || ! is_string($structured['brief'] ?? null) || ! is_array($structured['outline'] ?? null)) {
            throw ValidationException::withMessages(['brief' => 'AI brief output did not match the planner contract.']);
        }

        $item->update([
            'brief' => ['text' => $structured['brief']],
            'outline' => array_values($structured['outline']),
            'status' => 'brief',
            'version' => $item->version + 1,
        ]);
        $this->history($item, 'brief_generated');
        $this->audit->record('ai.planner.brief_generated', [
            'correlation_id' => $result['correlation_id'],
            'version' => $item->version,
        ], 'AiPlannerItem', $item->id);

        return $item->fresh();
    }

    public function generateDraft(AiPlannerItem $item): AiPlannerItem
    {
        $this->assertOwnedItem($item);
        if (! is_array($item->brief) || blank($item->brief['text'] ?? null)) {
            throw ValidationException::withMessages(['brief' => 'A content brief is required before draft generation.']);
        }

        $result = $this->generator->generate([
            'workflow' => 'content-planner.draft',
            'prompt_key' => 'content.draft',
            'variables' => [
                'title' => $item->title,
                'brief' => $item->brief['text'],
                'outline' => $item->outline ?? [],
            ],
            'site_id' => $item->site_id,
            'max_output_tokens' => 4000,
        ]);

        $structured = $result['structured'] ?? null;
        if (! is_array($structured) || ! is_string($structured['draft_html'] ?? null)) {
            throw ValidationException::withMessages(['draft' => 'AI draft output did not match the planner contract.']);
        }

        $item->update([
            'draft_content' => $structured['draft_html'],
            'status' => 'draft',
            'version' => $item->version + 1,
        ]);
        $this->history($item, 'draft_generated');
        $this->audit->record('ai.planner.draft_generated', [
            'correlation_id' => $result['correlation_id'],
            'version' => $item->version,
        ], 'AiPlannerItem', $item->id);

        return $item->fresh();
    }

    public function requestApproval(AiPlannerItem $item): AiPlannerItem
    {
        $this->assertOwnedItem($item);
        if (blank($item->draft_content)) {
            throw ValidationException::withMessages(['draft' => 'A draft is required before approval submission.']);
        }

        $reference = $this->approval->submit($item, $this->context->membership()->user_id);
        $item->update([
            'status' => 'review',
            'approval_reference' => $reference,
            'version' => $item->version + 1,
        ]);
        $this->history($item, 'approval_requested');
        $this->audit->record('ai.planner.approval_requested', [
            'approval_reference' => $reference,
            'version' => $item->version,
        ], 'AiPlannerItem', $item->id);

        return $item->fresh();
    }

    public function counts(): array
    {
        $query = $this->ownedQuery();

        return [
            'total' => (clone $query)->count(),
            'ideas' => (clone $query)->where('status', 'idea')->count(),
            'drafts' => (clone $query)->whereIn('status', ['brief', 'draft'])->count(),
            'review' => (clone $query)->where('status', 'review')->count(),
            'scheduled' => (clone $query)->where('status', 'scheduled')->count(),
            'published' => (clone $query)->where('status', 'published')->count(),
        ];
    }

    public function serialize(AiPlannerItem $item): array
    {
        return [
            'id' => $item->id,
            'site_id' => $item->site_id,
            'title' => $item->title,
            'idea' => $item->idea,
            'keywords' => $item->keywords ?? [],
            'topics' => $item->topics ?? [],
            'brief' => $item->brief,
            'outline' => $item->outline,
            'draft_content' => $item->draft_content,
            'status' => $item->status,
            'scheduled_at' => $item->scheduled_at?->toIso8601String(),
            'approval_reference' => $item->approval_reference,
            'version' => $item->version,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    private function ownedQuery(): Builder
    {
        return AiPlannerItem::query()
            ->where('user_id', $this->context->membership()->user_id);
    }

    private function assertOwnedItem(AiPlannerItem $item): void
    {
        abort_unless(
            $item->tenant_id === $this->context->id()
                && $item->user_id === $this->context->membership()->user_id,
            404,
        );
        $this->sites->assertOwned($item->site_id);
    }

    private function history(AiPlannerItem $item, string $action): void
    {
        AiPlannerHistory::query()->create([
            'ai_planner_item_id' => $item->id,
            'version' => $item->version,
            'action' => $action,
            'snapshot' => $this->serialize($item),
            'actor_user_id' => $this->context->membership()->user_id,
            'created_at' => now(),
        ]);
    }
}
