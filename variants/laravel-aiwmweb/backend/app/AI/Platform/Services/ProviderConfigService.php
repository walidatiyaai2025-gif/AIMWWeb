<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Enums\ProviderReadiness;
use App\Models\AiProviderProfile;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class ProviderConfigService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderSecretStore $secrets,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    public function list(): array
    {
        return AiProviderProfile::query()
            ->with('models')
            ->orderBy('priority')
            ->orderBy('provider_key')
            ->get()
            ->map(fn (AiProviderProfile $provider) => $this->serialize($provider))
            ->all();
    }

    public function save(?AiProviderProfile $provider, array $input): AiProviderProfile
    {
        $provider ??= new AiProviderProfile;
        $adapterKey = (string) ($input['adapter_key'] ?? $provider->adapter_key);
        if (! in_array($adapterKey, $this->registry->adapterKeys(), true)) {
            throw ValidationException::withMessages(['adapter_key' => 'Unsupported AI provider adapter.']);
        }

        $provider->fill(Arr::only($input, [
            'provider_key',
            'adapter_key',
            'display_name',
            'endpoint',
            'default_model',
            'enabled',
            'priority',
            'timeout_seconds',
            'max_attempts',
            'automatic_failover',
            'limits',
            'settings',
        ]));
        $provider->priority = min(max((int) ($provider->priority ?: 10), 1), 100);
        $provider->timeout_seconds = min(max((int) ($provider->timeout_seconds ?: 30), 2), 120);
        $provider->max_attempts = min(max((int) ($provider->max_attempts ?: 2), 1), 3);
        $provider->readiness_state = $provider->enabled
            ? ProviderReadiness::Unreachable
            : ProviderReadiness::Disabled;
        $provider->readiness_checked_at = null;
        $provider->readiness_error = $provider->enabled ? 'Readiness has not been verified.' : null;
        $provider->save();

        if (array_key_exists('api_key', $input) && filled($input['api_key'])) {
            $this->secrets->put($provider, (string) $input['api_key']);
        }
        if (($input['clear_api_key'] ?? false) === true) {
            $this->secrets->clear($provider);
        }

        $client = $this->registry->get($provider->adapter_key);
        $apiKeyOptional = (bool) data_get($provider->settings, 'api_key_optional', false);
        if ($provider->enabled && $client->requiresApiKey() && ! $apiKeyOptional && ! $this->secrets->has($provider)) {
            $provider->update([
                'readiness_state' => ProviderReadiness::NotConfigured,
                'readiness_error' => 'API credential is not configured.',
            ]);
        }

        $this->audit->record('ai.provider.configured', [
            'provider_key' => $provider->provider_key,
            'adapter_key' => $provider->adapter_key,
            'enabled' => $provider->enabled,
            'priority' => $provider->priority,
            'has_api_key' => $this->secrets->has($provider),
        ], 'AiProviderProfile', $provider->id);

        return $provider->fresh('models');
    }

    public function clearApiKey(AiProviderProfile $provider): void
    {
        $this->secrets->clear($provider);
        $provider->update([
            'readiness_state' => ProviderReadiness::NotConfigured,
            'readiness_checked_at' => now(),
            'readiness_error' => 'API credential is not configured.',
        ]);
        $this->audit->record('ai.provider.credential_cleared', [
            'provider_key' => $provider->provider_key,
        ], 'AiProviderProfile', $provider->id);
    }

    public function serialize(AiProviderProfile $provider): array
    {
        return [
            'id' => $provider->id,
            'provider_key' => $provider->provider_key,
            'adapter_key' => $provider->adapter_key,
            'display_name' => $provider->display_name,
            'endpoint' => $provider->endpoint,
            'default_model' => $provider->default_model,
            'enabled' => $provider->enabled,
            'priority' => $provider->priority,
            'timeout_seconds' => $provider->timeout_seconds,
            'max_attempts' => $provider->max_attempts,
            'automatic_failover' => $provider->automatic_failover,
            'limits' => $provider->limits ?? [],
            'settings' => $provider->settings ?? [],
            'readiness' => $provider->readiness_state?->value ?? ProviderReadiness::NotConfigured->value,
            'readiness_checked_at' => $provider->readiness_checked_at?->toIso8601String(),
            'readiness_error' => $provider->readiness_error,
            'has_api_key' => $this->secrets->has($provider),
            'models' => $provider->models->map(fn ($model) => [
                'id' => $model->id,
                'model_key' => $model->model_key,
                'display_name' => $model->display_name,
                'enabled' => $model->enabled,
                'capabilities' => $model->capabilities ?? [],
                'context_window' => $model->context_window,
                'max_output_tokens' => $model->max_output_tokens,
                'input_cost_per_million' => $model->input_cost_per_million,
                'output_cost_per_million' => $model->output_cost_per_million,
                'currency' => $model->currency,
            ])->values()->all(),
        ];
    }
}
