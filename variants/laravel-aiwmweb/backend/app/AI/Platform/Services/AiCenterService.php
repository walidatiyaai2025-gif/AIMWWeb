<?php

namespace App\AI\Platform\Services;

use App\Models\AiGenerationRecord;

final class AiCenterService
{
    public function __construct(
        private readonly ProviderConfigService $providers,
        private readonly ModelCatalogService $models,
        private readonly PromptRegistryService $prompts,
        private readonly AiUsageService $usage,
        private readonly AiContentPlannerService $planner,
    ) {}

    public function snapshot(array $filters = []): array
    {
        $usage = $this->usage->report($filters);

        return [
            'providers' => $this->providers->list(),
            'models' => $this->models->list(),
            'prompts' => $this->prompts->all(),
            'usage' => $usage['summary'],
            'provider_usage' => $usage['providers'],
            'workflow_usage' => $usage['workflows'],
            'recent_activity' => $usage['recent'],
            'failed_requests' => $usage['failed'],
            'quota_or_policy_failures' => AiGenerationRecord::query()
                ->where('status', 'failed')
                ->whereIn('failure_kind', [
                    'quota_exceeded',
                    'quota_backend_unavailable',
                    'policy_rejection',
                    'invalid_output',
                ])
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (AiGenerationRecord $record) => [
                    'id' => $record->id,
                    'workflow' => $record->workflow,
                    'failure_kind' => $record->failure_kind,
                    'correlation_id' => $record->correlation_id,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])
                ->all(),
            'planner' => $this->planner->counts(),
        ];
    }
}
