<?php

namespace App\AI\Platform\Services;

use App\Authorization\TenantAuthorizer;
use App\Models\AiProviderProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-authorized compatibility boundary for the canonical AIMWWeb
 * AIProviderSettingsAdministrationService operations.
 *
 * ProviderConfigService remains the single implementation for encrypted
 * credentials, readiness truth, audit history and tenant-scoped persistence.
 */
final class AIProviderSettingsAdministrationService
{
    public function __construct(
        private readonly ProviderConfigService $providers,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function getAiSettingsAsync(): array
    {
        $this->authorizer->authorize('settings.manage');

        return ['providers' => $this->providers->list()];
    }

    public function saveAiSettingsAsync(array $settings): array
    {
        $this->authorizer->authorize('settings.manage');
        $inputs = array_values((array) ($settings['providers'] ?? []));

        if ($inputs === []) {
            throw ValidationException::withMessages(['providers' => 'At least one AI provider configuration is required.']);
        }

        DB::transaction(function () use ($inputs): void {
            foreach ($inputs as $input) {
                if (! is_array($input)) {
                    throw ValidationException::withMessages(['providers' => 'Each provider configuration must be an object.']);
                }

                $provider = $this->resolveProvider($input);
                $this->providers->save($provider, $input);
            }
        });

        return $this->getAiSettingsAsync();
    }

    public function clearAiProviderApiKeyAsync(string $provider): array
    {
        $this->authorizer->authorize('settings.manage');
        $profile = AiProviderProfile::query()->where('provider_key', $provider)->first();
        if (! $profile) {
            throw (new ModelNotFoundException)->setModel(AiProviderProfile::class, [$provider]);
        }

        $this->providers->clearApiKey($profile);

        return $this->providers->serialize($profile->fresh('models'));
    }

    public function getAsync(): array
    {
        return $this->getAiSettingsAsync();
    }

    public function saveAsync(array $settings): array
    {
        return $this->saveAiSettingsAsync($settings);
    }

    public function clearApiKeyAsync(string $provider): array
    {
        return $this->clearAiProviderApiKeyAsync($provider);
    }

    private function resolveProvider(array $input): ?AiProviderProfile
    {
        if (isset($input['id'])) {
            return AiProviderProfile::query()->findOrFail((int) $input['id']);
        }

        $key = trim((string) ($input['provider_key'] ?? ''));
        if ($key === '') {
            throw ValidationException::withMessages(['provider_key' => 'AI provider key is required.']);
        }

        return AiProviderProfile::query()->where('provider_key', $key)->first();
    }
}
