<?php

namespace App\Content;

use App\Models\ContentItem;
use App\Models\Site;
use Illuminate\Pagination\LengthAwarePaginator;

final class GlobalPostsTrashService
{
    public function __construct(private readonly ContentPlatformService $content) {}

    public function posts(array $filters = []): LengthAwarePaginator
    {
        $query = ContentItem::query()->where('type', 'post');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $paginator = $query
            ->latest('remote_modified_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 100));

        $siteNames = Site::query()
            ->whereIn('id', collect($paginator->items())->pluck('site_id')->unique()->values())
            ->pluck('name', 'id');

        return $paginator->through(fn (ContentItem $item): array => [
            'site_id' => (int) $item->site_id,
            'site_name' => (string) ($siteNames[$item->site_id] ?? ''),
            'wordpress_id' => (int) $item->remote_id,
            'title' => (string) ($item->title ?? ''),
            'status' => (string) ($item->status ?? ''),
            'modified_at' => $item->remote_modified_at?->toIso8601String(),
        ]);
    }

    public function trash(array $targets): array
    {
        $normalized = collect($targets)
            ->map(fn (array $target): array => [
                'site_id' => (int) $target['site_id'],
                'wordpress_id' => (int) $target['wordpress_id'],
            ])
            ->values();

        $siteIds = $normalized->pluck('site_id')->unique()->values();
        $sites = Site::query()->whereIn('id', $siteIds)->pluck('id');
        abort_unless($sites->count() === $siteIds->count(), 422, 'Global post selection includes an unavailable site.');

        $items = ContentItem::query()
            ->where('type', 'post')
            ->where(function ($query) use ($normalized): void {
                foreach ($normalized as $target) {
                    $query->orWhere(function ($itemQuery) use ($target): void {
                        $itemQuery
                            ->where('site_id', $target['site_id'])
                            ->where('remote_id', $target['wordpress_id']);
                    });
                }
            })
            ->get()
            ->keyBy(fn (ContentItem $item): string => $item->site_id.':'.$item->remote_id);

        abort_unless($items->count() === $normalized->count(), 422, 'Global post selection includes unavailable content.');

        $results = [];
        $succeeded = 0;

        foreach ($normalized as $target) {
            $key = $target['site_id'].':'.$target['wordpress_id'];
            /** @var ContentItem $item */
            $item = $items->get($key);

            try {
                $wasAlreadyTrashed = $item->status === 'trash';

                if (! $wasAlreadyTrashed) {
                    $this->content->mutateContent(
                        $target['site_id'],
                        'post',
                        $target['wordpress_id'],
                        'trash',
                        [],
                        [
                            'hash' => $item->remote_hash,
                            'modified_at' => $item->remote_modified_at?->toIso8601String(),
                            'version' => $item->remote_version,
                        ],
                    );
                }

                $fresh = $this->content->reconcileContentItem(
                    $target['site_id'],
                    'post',
                    $target['wordpress_id'],
                );

                if ($fresh->status !== 'trash') {
                    $results[] = [...$target, 'status' => 'failed'];

                    continue;
                }

                $succeeded++;
                $results[] = [
                    ...$target,
                    'status' => $wasAlreadyTrashed ? 'already_trashed' : 'trashed',
                ];
            } catch (ContentConflictException) {
                $fresh = $this->reconcileAfterConflict($target['site_id'], $target['wordpress_id']);

                if ($fresh?->status === 'trash') {
                    $succeeded++;
                    $results[] = [...$target, 'status' => 'already_trashed'];

                    continue;
                }

                $results[] = [...$target, 'status' => 'conflict'];
            } catch (\Throwable) {
                $results[] = [...$target, 'status' => 'failed'];
            }
        }

        $failed = $normalized->count() - $succeeded;

        return [
            'succeeded' => $succeeded,
            'failed' => $failed,
            'total' => $normalized->count(),
            'message' => $failed === 0
                ? 'Selected posts moved to trash.'
                : 'Global post trash completed with failures.',
            'results' => $results,
        ];
    }

    private function reconcileAfterConflict(int $siteId, int $wordpressId): ?ContentItem
    {
        try {
            return $this->content->reconcileContentItem($siteId, 'post', $wordpressId);
        } catch (\Throwable) {
            return null;
        }
    }
}
