<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\AiPromptTemplate;
use App\Models\AiUsageRecord;
use App\Models\Approval;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiCenterReadController extends Controller
{
    public const METADATA_REFRESH_OPERATION_ID = 'AIMW-AI-953A6C0D98';

    public function __invoke(Request $request, TenantAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize('ai.use');

        $prompts = AiPromptTemplate::query()
            ->where('enabled', true)
            ->get(['id', 'stable_key', 'domain', 'title', 'current_version', 'enabled'])
            ->sortBy(
                static fn (AiPromptTemplate $prompt): string => strtolower((string) $prompt->stable_key),
                SORT_STRING,
            )
            ->map(fn (AiPromptTemplate $prompt): array => [
                'id' => (int) $prompt->id,
                'key' => (string) $prompt->stable_key,
                'domain' => (string) $prompt->domain,
                'title' => (string) $prompt->title,
                'version' => (int) $prompt->current_version,
                'enabled' => (bool) $prompt->enabled,
            ])
            ->values();

        $recentUsageCount = AiUsageRecord::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id'])
            ->count();

        $sites = Site::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (Site $site): array => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
            ])
            ->values();

        $approval = Approval::query()
            ->where('actor_user_id', $request->user()->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => $prompts,
            'total' => $prompts->count(),
            'current_page' => 1,
            'last_page' => 1,
            'meta' => [
                'operation_id' => self::METADATA_REFRESH_OPERATION_ID,
                'locale' => app()->getLocale(),
                'available_prompts' => $prompts->count(),
                'recent_usage_count' => $recentUsageCount,
                'sites' => $sites,
                'approval' => $approval ? [
                    'id' => (int) $approval->id,
                    'status' => (string) $approval->status,
                    'decided_at' => $approval->decided_at?->toISOString(),
                    'updated_at' => $approval->updated_at?->toISOString(),
                ] : null,
            ],
        ]);
    }
}
