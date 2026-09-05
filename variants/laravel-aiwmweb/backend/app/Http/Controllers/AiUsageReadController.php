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
        ]);

        $siteId = null;
        if (array_key_exists('site', $validated) && $validated['site'] !== null) {
            $site = Site::query()->findOrFail((int) $validated['site']);
            $siteId = (int) $site->getKey();
        }

        $report = $this->usage->report([
            'user_id' => (int) $request->user()->getKey(),
            'site_id' => $siteId,
            'take' => self::SOURCE_LOAD_TAKE,
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

        // Preserve the full usage dashboard payload while also publishing the
        // generic live-resource envelope consumed by the React workspace.
        $report['data'] = $report['recent'];
        $report['total'] = $report['summary']['total_calls'];

        return response()->json($report);
    }
}
