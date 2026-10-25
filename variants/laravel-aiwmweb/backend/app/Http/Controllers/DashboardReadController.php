<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\ContentItem;
use App\Models\Execution;
use App\Models\MediaItem;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class DashboardReadController extends Controller
{
    public const OPERATION_ID = 'AIMW-CONT-519B93F503';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function __invoke(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');

        $sites = Site::query()->count();
        $connectedSites = Site::query()->where('connection_status', 'connected')->count();
        $posts = ContentItem::query()->where('type', 'post')->count();
        $pages = ContentItem::query()->where('type', 'page')->count();
        $media = MediaItem::query()->count();
        $activeJobs = Execution::query()
            ->whereIn('status', ['queued', 'running', 'in_progress'])
            ->count();
        $failedJobs = Execution::query()
            ->where(function ($query): void {
                $query->where('status', 'failed')->orWhereNotNull('failure');
            })
            ->count();

        $lastSyncRaw = Site::query()->whereNotNull('last_sync_at')->max('last_sync_at');
        $lastSync = $lastSyncRaw !== null
            ? CarbonImmutable::parse((string) $lastSyncRaw)->toIso8601String()
            : null;

        return response()->json([
            'metrics' => [
                [
                    'key' => 'sites',
                    'label' => 'Sites',
                    'value' => $sites,
                    'detail' => "{$connectedSites} connected",
                ],
                [
                    'key' => 'content',
                    'label' => 'Cached content',
                    'value' => $posts + $pages,
                    'detail' => "{$posts} posts · {$pages} pages",
                ],
                [
                    'key' => 'media',
                    'label' => 'Media',
                    'value' => $media,
                    'detail' => 'Cached media items',
                ],
                [
                    'key' => 'active_jobs',
                    'label' => 'Active jobs',
                    'value' => $activeJobs,
                    'detail' => "{$failedJobs} failed",
                ],
                [
                    'key' => 'last_sync',
                    'label' => 'Last sync',
                    'value' => $lastSync ?? '—',
                    'detail' => $lastSync === null ? 'No completed site sync recorded' : 'Latest tenant site sync',
                ],
            ],
            'summary' => [
                'sites' => ['total' => $sites, 'connected' => $connectedSites],
                'content' => ['posts' => $posts, 'pages' => $pages, 'media' => $media],
                'jobs' => ['active' => $activeJobs, 'failed' => $failedJobs],
                'last_sync_at' => $lastSync,
            ],
        ]);
    }
}
