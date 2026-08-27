<?php

namespace App\AI\Platform\Services;

use App\Models\AiProviderProfile;
use App\Models\TenantSecret;

final class ProviderSecretStore
{
    public function get(AiProviderProfile $provider): ?string
    {
        return TenantSecret::query()
            ->where('name', $provider->secretName())
            ->value('encrypted_value');
    }

    public function has(AiProviderProfile $provider): bool
    {
        return TenantSecret::query()
            ->where('name', $provider->secretName())
            ->exists();
    }

    public function put(AiProviderProfile $provider, string $plainApiKey): void
    {
        TenantSecret::query()->updateOrCreate(
            ['name' => $provider->secretName()],
            ['encrypted_value' => trim($plainApiKey)],
        );
    }

    public function clear(AiProviderProfile $provider): void
    {
        TenantSecret::query()
            ->where('name', $provider->secretName())
            ->delete();
    }
}
