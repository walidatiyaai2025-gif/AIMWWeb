<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\Site;
use App\Models\TaxonomyTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContentExplorerReadController extends Controller
{
    public const OPERATION_ID = 'AIMW-CONT-5CF47CBE2A';

    public function __invoke(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('sites.view');

        $ownedSite = Site::query()->findOrFail($site);
        // Reject array-shaped or oversized query input before casting it to a
        // string or passing it to SQL. An invalid filter is not an empty result.
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $type = strtolower(trim((string) ($filters['type'] ?? 'all')));
        abort_unless(in_array($type, ['all', 'post', 'page'], true), 422, 'Explorer type must be all, post, or page.');

        $perPage = min(100, (int) ($filters['per_page'] ?? 20));
        $query = ContentItem::query()->where('site_id', $site);

        if ($type !== 'all') {
            $query->where('type', $type);
        }
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            // A user searches literal WordPress titles/slugs, not SQL LIKE
            // patterns. Parameter binding alone does not escape % or _.
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $pattern = '%'.$needle.'%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        $items = $query
            ->orderByDesc('remote_modified_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = $items->getCollection()->map(static fn (ContentItem $item): array => [
            'id' => (int) $item->id,
            'wordpress_id' => $item->remote_id === null ? null : (int) $item->remote_id,
            'type' => (string) $item->type,
            'title' => (string) ($item->title ?? ''),
            'slug' => (string) ($item->slug ?? ''),
            'status' => (string) $item->status,
            'link' => $item->link,
            'modified_at' => $item->remote_modified_at?->toIso8601String(),
            'synced_at' => $item->synced_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'data' => $rows,
            'total' => $items->total(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'meta' => [
                'operation_id' => self::OPERATION_ID,
                'site' => [
                    'id' => (int) $ownedSite->getKey(),
                    'name' => (string) $ownedSite->name,
                    'url' => (string) $ownedSite->url,
                    'status' => (string) $ownedSite->status,
                    'connection_status' => (string) $ownedSite->connection_status,
                ],
                'totals' => [
                    'posts' => ContentItem::query()->where('site_id', $site)->where('type', 'post')->count(),
                    'pages' => ContentItem::query()->where('site_id', $site)->where('type', 'page')->count(),
                    'categories' => TaxonomyTerm::query()->where('site_id', $site)->where('taxonomy', 'category')->count(),
                    'tags' => TaxonomyTerm::query()->where('site_id', $site)->where('taxonomy', 'post_tag')->count(),
                    'media' => MediaItem::query()->where('site_id', $site)->count(),
                ],
                'last_synchronized_at' => $ownedSite->last_sync_at?->toIso8601String(),
            ],
        ]);
    }
}
