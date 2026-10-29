<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Jobs\BulkContentMutationJob;
use App\Models\ContentItem;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalPostsPublishController extends Controller
{
    public const OPERATION_ID = 'AIMW-SYNC-33EE696FF7';

    public function __construct(private readonly TenantContext $tenant) {}

    public function publish(Request $request, TenantAuthorizer $auth, string $tenant): JsonResponse
    {
        $auth->authorize('content.edit');

        $callerOwned = ['tenant', 'tenant_id', 'user_id', 'actor_user_id'];
        abort_if(
            array_intersect(array_keys($request->all()), $callerOwned) !== [],
            422,
            'Global post publish does not accept caller-owned identity fields.',
        );

        $data = $request->validate([
            'targets' => 'required|array|min:1|max:100',
            'targets.*.site_id' => 'required|integer|min:1',
            'targets.*.wordpress_id' => 'required|integer|min:1',
        ]);

        $targets = collect($data['targets'])
            ->map(fn (array $target): array => [
                'site_id' => (int) $target['site_id'],
                'wordpress_id' => (int) $target['wordpress_id'],
            ])
            ->values();

        $unique = $targets->unique(
            fn (array $target): string => $target['site_id'].':'.$target['wordpress_id'],
        );
        abort_if($unique->count() !== $targets->count(), 422, 'Global post publish targets must be unique.');

        $siteIds = $targets->pluck('site_id')->unique()->values();
        $sites = Site::query()->whereIn('id', $siteIds)->pluck('id');
        abort_unless($sites->count() === $siteIds->count(), 422, 'Global post publish includes an unavailable site.');

        $items = ContentItem::query()
            ->where('type', 'post')
            ->where(function ($query) use ($targets): void {
                foreach ($targets as $target) {
                    $query->orWhere(function ($itemQuery) use ($target): void {
                        $itemQuery
                            ->where('site_id', $target['site_id'])
                            ->where('remote_id', $target['wordpress_id']);
                    });
                }
            })
            ->get()
            ->keyBy(fn (ContentItem $item): string => $item->site_id.':'.$item->remote_id);

        abort_unless($items->count() === $targets->count(), 422, 'Global post publish includes unavailable content.');

        $jobs = [];
        foreach ($targets->groupBy('site_id') as $siteId => $siteTargets) {
            $contentIds = $siteTargets
                ->map(fn (array $target): int => (int) $items->get(
                    $target['site_id'].':'.$target['wordpress_id'],
                )->getKey())
                ->unique()
                ->values()
                ->all();

            BulkContentMutationJob::dispatch(
                $this->tenant->id(),
                (int) $siteId,
                $contentIds,
                'publish',
            );

            $jobs[] = [
                'site_id' => (int) $siteId,
                'count' => count($contentIds),
                'state' => 'queued',
            ];
        }

        return response()->json([
            'state' => 'queued',
            'count' => $targets->count(),
            'site_jobs' => count($jobs),
            'jobs' => $jobs,
        ], 202);
    }
}
