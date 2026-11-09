<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\AiUsageService;
use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiUsageReadController extends Controller
{
    private const SOURCE_LOAD_TAKE = 10_000;

    public function __construct(
        private readonly AiUsageService $usage,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizer->authorize('ai.viewUsage');

        $validated = $request->validate([
            'site' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
        ]);

        $siteId = null;
        if (array_key_exists('site', $validated) && $validated['site'] !== null) {
            $site = Site::query()->findOrFail((int) $validated['site']);
            $siteId = (int) $site->getKey();
        }

        $report = $this->usage->report([
            'user_id' => (int) $request->user()->getKey(),
            'site_id' => $siteId,
            'take' => $request->hasAny(['page', 'per_page']) ? 100 : self::SOURCE_LOAD_TAKE,
        ]);

        $report['sites'] = Site::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Site $site): array => [
                'id' => (int) $site->getKey(),
                'name' => $site->name,
            ])
            ->values()
            ->all();

        // Existing clients keep the legacy full snapshot contract; opted-in
        // browser grids get bounded real pages, not the first 10k rows sliced locally.
        if ($request->hasAny(['page', 'per_page'])) {
            $paging = $this->usage->pagedRecent([
                'user_id' => (int) $request->user()->getKey(),
                'site_id' => $siteId,
            ], (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 25));
            $report['recent'] = $paging['data'];
            $report['current_page'] = $paging['current_page'];
            $report['last_page'] = $paging['last_page'];
            $report['per_page'] = $paging['per_page'];
        }

        // Preserve the full usage dashboard payload while also publishing the
        // generic live-resource envelope consumed by the React workspace.
        $report['data'] = $report['recent'];
        $report['total'] = $report['summary']['total_calls'];

        return response()->json($report);
    }
}
