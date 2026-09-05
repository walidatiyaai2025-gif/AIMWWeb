<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\AiPromptTemplate;
use App\Models\AiUsageRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiCenterReadController extends Controller
{
    public function __invoke(Request $request, TenantAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize('ai.use');

        $prompts = AiPromptTemplate::query()
            ->where('enabled', true)
            ->orderBy('title')
            ->orderBy('id')
            ->get(['id', 'stable_key', 'domain', 'title', 'current_version', 'enabled'])
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
            ->limit(100)
            ->get(['id'])
            ->count();

        return response()->json([
            'data' => $prompts,
            'total' => $prompts->count(),
            'current_page' => 1,
            'last_page' => 1,
            'meta' => [
                'available_prompts' => $prompts->count(),
                'recent_usage_count' => $recentUsageCount,
            ],
        ]);
    }
}
