<?php

namespace App\Email\Services;

use App\Models\MailConfiguration;
use App\Models\TenantSecret;

final class EmailSecretStore
{
    public function put(MailConfiguration $configuration, string $secret): void
    {
        TenantSecret::query()->updateOrCreate(
            ['name' => $this->key($configuration)],
            ['encrypted_value' => $secret],
        );
    }

    public function get(MailConfiguration $configuration): ?string
    {
        return TenantSecret::query()->where('name', $this->key($configuration))->value('encrypted_value');
    }

    public function clear(MailConfiguration $configuration): void
    {
        TenantSecret::query()->where('name', $this->key($configuration))->delete();
    }

    public function has(MailConfiguration $configuration): bool
    {
        return TenantSecret::query()->where('name', $this->key($configuration))->exists();
    }

    private function key(MailConfiguration $configuration): string
    {
        return "email.transport.{$configuration->id}.credential";
    }
}
