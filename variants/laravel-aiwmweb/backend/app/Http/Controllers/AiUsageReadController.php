<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\AiUsageService;
use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiUsageReadController extends Controller
{
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
            'take' => 1000,
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

        return response()->json($report);
    }
}
