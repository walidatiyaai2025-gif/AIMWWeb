<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Jobs\BulkContentMutationJob;
use App\Models\ContentItem;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalPagesDraftController extends Controller
{
    public const OPERATION_ID = 'AIMW-SYNC-3389FB622C';

    public function __construct(private readonly TenantContext $tenant) {}

    public function draft(Request $request, TenantAuthorizer $auth, string $tenant): JsonResponse
    {
        $auth->authorize('content.edit');

        $callerOwned = ['tenant', 'tenant_id', 'user_id', 'actor_user_id'];
        abort_if(
            array_intersect(array_keys($request->all()), $callerOwned) !== [],
            422,
            'Bulk status mutation cannot accept caller-owned tenant or actor identity.',
        );

        $data = $request->validate([
            'targets' => 'required|array|min:1|max:100',
            'targets.*.site_id' => 'required|integer|min:1',
            'targets.*.wordpress_id' => 'required|integer|min:1',
        ]);

        $targets = collect($data['targets'])->map(fn (array $target): array => [
            'site_id' => (int) $target['site_id'],
            'wordpress_id' => (int) $target['wordpress_id'],
        ])->values();

        $unique = $targets->unique(fn (array $target): string => $target['site_id'].':'.$target['wordpress_id']);
        abort_if($unique->count() !== $targets->count(), 422, 'Duplicate target identifiers are not allowed.');

        $siteIds = $targets->pluck('site_id')->unique()->values();
        $sites = Site::query()->whereIn('id', $siteIds)->pluck('id');
        abort_unless($sites->count() === $siteIds->count(), 422, 'Selected site is unavailable to the active tenant.');

        $items = ContentItem::query()
            ->where('type', 'page')
            ->where(function ($query) use ($targets): void {
                foreach ($targets as $target) {
                    $query->orWhere(function ($row) use ($target): void {
                        $row->where('site_id', $target['site_id'])->where('remote_id', $target['wordpress_id']);
                    });
                }
            })
            ->get()
            ->keyBy(fn (ContentItem $row): string => $row->site_id.':'.$row->remote_id);
        abort_unless($items->count() === $targets->count(), 422, 'Selection includes unavailable tenant content.');

        $jobs = [];
        foreach ($targets->groupBy('site_id') as $siteId => $batch) {
            $ids = $batch->map(fn (array $target): int => (int) $items->get(
                $target['site_id'].':'.$target['wordpress_id'],
            )->getKey())->unique()->values()->all();

            BulkContentMutationJob::dispatch(
                $this->tenant->id(),
                (int) $siteId,
                $ids,
                'draft'
            );
            $jobs[] = ['site_id' => (int) $siteId, 'count' => count($ids), 'state' => 'queued'];
        }

        return response()->json([
            'operation_id' => self::OPERATION_ID,
            'state' => 'queued',
            'count' => $targets->count(),
            'site_jobs' => count($jobs),
            'jobs' => $jobs,
        ], 202);
    }
}
