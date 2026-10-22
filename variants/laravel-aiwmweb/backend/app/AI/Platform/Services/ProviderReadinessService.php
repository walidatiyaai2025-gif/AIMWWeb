<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Enums\ProviderReadiness;
use App\AI\Platform\Support\AiSafetyPolicy;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Services\AuditLogger;

final class ProviderReadinessService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderSecretStore $secrets,
        private readonly AiSafetyPolicy $safety,
        private readonly AuditLogger $audit,
    ) {}

    public function check(AiProviderProfile $provider, ?AiModelProfile $model = null): array
    {
        if (! $provider->enabled) {
            return $this->persist($provider, ProviderReadiness::Disabled, null);
        }

        $model ??= $provider->models()
            ->where('enabled', true)
            ->when($provider->default_model, fn ($query, $value) => $query->orderByRaw('CASE WHEN model_key = ? THEN 0 ELSE 1 END', [$value]))
            ->first();

        if (! $model) {
            return $this->persist($provider, ProviderReadiness::ModelUnavailable, 'No enabled model is configured.');
        }

        $client = $this->registry->get($provider->adapter_key);
        $apiKey = $this->secrets->get($provider);
        $apiKeyOptional = (bool) data_get($provider->settings, 'api_key_optional', false);
        if ($client->requiresApiKey() && ! $apiKeyOptional && blank($apiKey)) {
            return $this->persist($provider, ProviderReadiness::NotConfigured, 'API credential is not configured.');
        }

        try {
            $result = $client->check($provider, $apiKey, $model);
            $state = ProviderReadiness::from((string) $result['state']);
            $message = isset($result['message']) ? $this->safety->sanitizeError((string) $result['message']) : null;

            return $this->persist($provider, $state, $message);
        } catch (\Throwable $exception) {
            return $this->persist(
                $provider,
                ProviderReadiness::Unreachable,
                $this->safety->sanitizeError($exception->getMessage()),
            );
        }
    }

    private function persist(AiProviderProfile $provider, ProviderReadiness $state, ?string $message): array
    {
        $provider->update([
            'readiness_state' => $state,
            'readiness_checked_at' => now(),
            'readiness_error' => $message,
            'last_rate_limited_at' => $state === ProviderReadiness::RateLimited ? now() : $provider->last_rate_limited_at,
        ]);

        $this->audit->record('ai.provider.readiness_checked', [
            'provider_key' => $provider->provider_key,
            'state' => $state->value,
        ], 'AiProviderProfile', $provider->id);

        return [
            'state' => $state->value,
            'message' => $message,
            'checked_at' => $provider->fresh()->readiness_checked_at?->toIso8601String(),
        ];
    }
}
