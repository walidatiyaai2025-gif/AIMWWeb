<?php

namespace App\Email\Services;

use App\Email\Contracts\EmailTransport;
use App\Models\MailConfiguration;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class MailConfigurationService
{
    public function __construct(
        private readonly EmailSecretStore $secrets,
        private readonly EmailTransport $transport,
        private readonly AuditLogger $audit,
    ) {}

    public function get(string $key = 'default'): array
    {
        $configuration = MailConfiguration::query()->where('configuration_key', $key)->first();

        return $configuration ? $this->serialize($configuration) : ['configuration_key' => $key, 'configured' => false, 'has_secret' => false];
    }

    public function save(string $key, array $input): MailConfiguration
    {
        if (! preg_match('/^[a-zA-Z0-9:._-]{1,160}$/', $key)) {
            throw ValidationException::withMessages(['configuration_key' => 'Invalid mail configuration key.']);
        }
        if (($input['transport'] ?? 'smtp') !== 'smtp') {
            throw ValidationException::withMessages(['transport' => 'Only canonical SMTP transport is currently supported.']);
        }
        if (array_key_exists('site_id', $input) && $input['site_id'] !== null) {
            Site::query()->findOrFail((int) $input['site_id']);
        }

        $configuration = MailConfiguration::query()->firstOrNew(['configuration_key' => $key]);
        $configuration->fill(Arr::only($input, [
            'site_id', 'transport', 'host', 'port', 'encryption', 'username',
            'from_address', 'from_name', 'reply_to', 'enabled', 'timeout_seconds', 'max_attempts', 'settings',
        ]));
        $configuration->transport = 'smtp';
        $configuration->port = min(max((int) ($configuration->port ?: 587), 1), 65535);
        $configuration->timeout_seconds = min(max((int) ($configuration->timeout_seconds ?: 20), 2), 120);
        $configuration->max_attempts = min(max((int) ($configuration->max_attempts ?: 4), 1), 5);
        $configuration->save();

        if (filled($input['secret'] ?? null)) {
            $this->secrets->put($configuration, (string) $input['secret']);
        }
        if (($input['clear_secret'] ?? false) === true) {
            $this->secrets->clear($configuration);
        }

        $this->audit->record('email.configuration.changed', [
            'configuration_key' => $key,
            'site_id' => $configuration->site_id,
            'host' => $configuration->host,
            'port' => $configuration->port,
            'enabled' => (bool) $configuration->enabled,
            'has_secret' => $this->secrets->has($configuration),
        ], MailConfiguration::class, $configuration->id);

        return $configuration;
    }

    public function diagnose(string $key = 'default'): array
    {
        $configuration = MailConfiguration::query()->where('configuration_key', $key)->firstOrFail();

        return $this->transport->diagnose($configuration, $this->secrets->get($configuration));
    }

    public function serialize(MailConfiguration $configuration): array
    {
        return [
            'id' => $configuration->id,
            'configuration_key' => $configuration->configuration_key,
            'site_id' => $configuration->site_id,
            'transport' => $configuration->transport,
            'host' => $configuration->host,
            'port' => $configuration->port,
            'encryption' => $configuration->encryption,
            'username' => $configuration->username,
            'from_address' => $configuration->from_address,
            'from_name' => $configuration->from_name,
            'reply_to' => $configuration->reply_to,
            'enabled' => (bool) $configuration->enabled,
            'timeout_seconds' => $configuration->timeout_seconds,
            'max_attempts' => $configuration->max_attempts,
            'settings' => $configuration->settings ?? [],
            'has_secret' => $this->secrets->has($configuration),
            'configured' => filled($configuration->host) && filled($configuration->from_address),
        ];
    }
}
