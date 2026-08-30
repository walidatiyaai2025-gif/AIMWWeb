<?php

namespace App\Email\Services;

use App\Authorization\TenantAuthorizer;
use App\Models\MailConfiguration;
use App\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-safe adaptation of the canonical SiteMailProfileService.
 *
 * SMTP secrets stay inside EmailSecretStore/MailConfigurationService and are
 * never returned by this compatibility boundary.
 */
final class SiteMailProfileService
{
    public function __construct(
        private readonly MailConfigurationService $configurations,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function getAsync(int $siteId): array
    {
        $this->authorizer->authorize('tenant.view');
        $this->site($siteId);
        $profile = $this->configurations->get($this->siteKey($siteId));

        return $this->view($siteId, $profile);
    }

    public function saveAsync(int $siteId, array $input): array
    {
        $this->authorizer->authorize('settings.manage');
        $this->site($siteId);
        $inherit = (bool) ($input['use_account_profile'] ?? false);

        if (! $inherit) {
            $host = trim((string) ($input['host'] ?? ''));
            $from = trim((string) ($input['from_address'] ?? ''));
            if ($host === '') {
                throw ValidationException::withMessages(['host' => 'SMTP host is required.']);
            }
            if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['from_address' => 'A valid from address is required.']);
            }
            $replyTo = trim((string) ($input['reply_to'] ?? $input['reply_to_address'] ?? ''));
            if ($replyTo !== '' && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['reply_to' => 'Reply-to address is invalid.']);
            }
        }

        $settings = (array) ($input['settings'] ?? []);
        $settings['use_account_profile'] = $inherit;

        $configuration = $this->configurations->save($this->siteKey($siteId), [
            'site_id' => $siteId,
            'transport' => 'smtp',
            'host' => $inherit ? null : trim((string) ($input['host'] ?? '')),
            'port' => (int) ($input['port'] ?? 587),
            'encryption' => $inherit ? null : ((bool) ($input['enable_ssl'] ?? true) ? 'tls' : null),
            'username' => $inherit ? null : trim((string) ($input['username'] ?? $input['user_name'] ?? '')),
            'from_address' => $inherit ? null : trim((string) ($input['from_address'] ?? '')),
            'from_name' => $inherit ? null : trim((string) ($input['from_name'] ?? '')),
            'reply_to' => $inherit ? null : trim((string) ($input['reply_to'] ?? $input['reply_to_address'] ?? '')),
            'enabled' => (bool) ($input['enabled'] ?? $input['is_enabled'] ?? false),
            'timeout_seconds' => (int) ($input['timeout_seconds'] ?? 20),
            'max_attempts' => (int) ($input['max_attempts'] ?? 4),
            'settings' => $settings,
            'secret' => $input['password'] ?? $input['secret'] ?? null,
        ]);

        return $this->view($siteId, $this->configurations->serialize($configuration));
    }

    public function clearPasswordAsync(int $siteId): void
    {
        $this->authorizer->authorize('settings.manage');
        $this->site($siteId);
        $configuration = MailConfiguration::query()->where('configuration_key', $this->siteKey($siteId))->first();
        if (! $configuration) {
            throw (new ModelNotFoundException)->setModel(MailConfiguration::class, [$this->siteKey($siteId)]);
        }

        $this->configurations->save($configuration->configuration_key, [
            ...$configuration->only([
                'site_id', 'transport', 'host', 'port', 'encryption', 'username',
                'from_address', 'from_name', 'reply_to', 'enabled', 'timeout_seconds', 'max_attempts', 'settings',
            ]),
            'clear_secret' => true,
        ]);
    }

    public function getDeliveryProfileAsync(int $siteId): ?array
    {
        $this->authorizer->authorize('tenant.view');
        $this->site($siteId);
        $siteConfiguration = $this->siteConfiguration($siteId);
        if (! $siteConfiguration || ! $siteConfiguration->enabled) {
            return null;
        }

        return $this->deliveryProfile($siteConfiguration, true);
    }

    public function getTestProfileAsync(int $siteId): array
    {
        $this->authorizer->authorize('settings.manage');
        $this->site($siteId);
        $siteConfiguration = $this->siteConfiguration($siteId);
        if (! $siteConfiguration) {
            throw ValidationException::withMessages(['mail_profile' => 'Save the site mail profile before running diagnostics.']);
        }

        return $this->deliveryProfile($siteConfiguration, false)
            ?? throw ValidationException::withMessages(['mail_profile' => 'The inherited account SMTP profile is not configured.']);
    }

    private function deliveryProfile(MailConfiguration $siteConfiguration, bool $requireEnabled): ?array
    {
        $inherit = (bool) data_get($siteConfiguration->settings, 'use_account_profile', false);
        $configuration = $inherit
            ? MailConfiguration::query()->where('configuration_key', 'default')->first()
            : $siteConfiguration;

        if (! $configuration || ($requireEnabled && ! $configuration->enabled)) {
            return null;
        }

        $serialized = $this->configurations->serialize($configuration);
        if (! ($serialized['configured'] ?? false)) {
            return null;
        }

        return [
            'configuration_key' => $serialized['configuration_key'],
            'site_id' => $siteConfiguration->site_id,
            'transport' => $serialized['transport'],
            'host' => $serialized['host'],
            'port' => $serialized['port'],
            'encryption' => $serialized['encryption'],
            'username' => $serialized['username'],
            'from_address' => $serialized['from_address'],
            'from_name' => $serialized['from_name'],
            'reply_to' => $serialized['reply_to'],
            'has_secret' => $serialized['has_secret'],
            'inherited' => $inherit,
        ];
    }

    private function siteConfiguration(int $siteId): ?MailConfiguration
    {
        return MailConfiguration::query()->where('configuration_key', $this->siteKey($siteId))->first();
    }

    private function site(int $siteId): Site
    {
        return Site::query()->findOrFail($siteId);
    }

    private function siteKey(int $siteId): string
    {
        return "site:{$siteId}";
    }

    private function view(int $siteId, array $profile): array
    {
        return [
            'site_id' => $siteId,
            'use_account_profile' => (bool) data_get($profile, 'settings.use_account_profile', true),
            'host' => (string) ($profile['host'] ?? ''),
            'port' => (int) ($profile['port'] ?? 587),
            'username' => (string) ($profile['username'] ?? ''),
            'has_saved_password' => (bool) ($profile['has_secret'] ?? false),
            'from_address' => (string) ($profile['from_address'] ?? ''),
            'from_name' => (string) ($profile['from_name'] ?? ''),
            'reply_to' => (string) ($profile['reply_to'] ?? ''),
            'enable_ssl' => in_array($profile['encryption'] ?? null, ['ssl', 'tls', 'starttls'], true),
            'enabled' => (bool) ($profile['enabled'] ?? false),
            'updated_at' => $profile['updated_at'] ?? null,
        ];
    }
}
