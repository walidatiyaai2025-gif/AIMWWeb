<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Enums\ProviderReadiness;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class ModelCatalogService
{
    public const CAPABILITIES = [
        'text_generation',
        'structured_json',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function list(?int $providerId = null): array
    {
        return AiModelProfile::query()
            ->with('provider')
            ->when($providerId, fn ($query, $value) => $query->where('ai_provider_profile_id', $value))
            ->orderBy('model_key')
            ->get()
            ->map(fn (AiModelProfile $model) => $this->serialize($model))
            ->all();
    }

    public function save(AiProviderProfile $provider, ?AiModelProfile $model, array $input): AiModelProfile
    {
        if ($model && $model->ai_provider_profile_id !== $provider->id) {
            abort(404);
        }

        $capabilities = array_values(array_unique(array_map('strval', (array) ($input['capabilities'] ?? []))));
        $unsupported = array_diff($capabilities, self::CAPABILITIES);
        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'capabilities' => 'Unsupported model capabilities: '.implode(', ', $unsupported),
            ]);
        }
        if (! in_array('text_generation', $capabilities, true)) {
            $capabilities[] = 'text_generation';
        }

        $model ??= new AiModelProfile(['ai_provider_profile_id' => $provider->id]);
        $model->fill(Arr::only($input, [
            'model_key',
            'display_name',
            'enabled',
            'context_window',
            'max_output_tokens',
            'input_cost_per_million',
            'output_cost_per_million',
            'currency',
            'metadata',
        ]));
        $model->capabilities = $capabilities;
        $model->ai_provider_profile_id = $provider->id;
        $model->currency = strtoupper((string) ($model->currency ?: 'USD'));
        $model->save();

        $this->audit->record('ai.model.configured', [
            'provider_key' => $provider->provider_key,
            'model_key' => $model->model_key,
            'enabled' => $model->enabled,
            'capabilities' => $model->capabilities,
        ], 'AiModelProfile', $model->id);

        return $model->fresh('provider');
    }

    public function candidates(array $requiredCapabilities, ?string $requestedModel = null): array
    {
        $required = array_values(array_unique(array_map('strval', $requiredCapabilities)));

        $providers = AiProviderProfile::query()
            ->where('enabled', true)
            ->where('readiness_state', ProviderReadiness::Ready->value)
            ->with(['models' => fn ($query) => $query->where('enabled', true)])
            ->orderBy('priority')
            ->get();

        $candidates = [];
        foreach ($providers as $provider) {
            foreach ($provider->models as $model) {
                if ($requestedModel && $model->model_key !== $requestedModel) {
                    continue;
                }
                if (array_diff($required, $model->capabilities ?? []) !== []) {
                    continue;
                }
                $candidates[] = ['provider' => $provider, 'model' => $model];
            }
        }

        return $candidates;
    }

    public function serialize(AiModelProfile $model): array
    {
        return [
            'id' => $model->id,
            'provider_id' => $model->ai_provider_profile_id,
            'provider_key' => $model->provider?->provider_key,
            'model_key' => $model->model_key,
            'display_name' => $model->display_name,
            'enabled' => $model->enabled,
            'capabilities' => $model->capabilities ?? [],
            'context_window' => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'input_cost_per_million' => $model->input_cost_per_million,
            'output_cost_per_million' => $model->output_cost_per_million,
            'currency' => $model->currency,
            'metadata' => $model->metadata ?? [],
        ];
    }
}
