<?php

namespace App\Email\Services;

use App\Authorization\TenantAuthorizer;
use App\Email\Contracts\EmailTransport;
use App\Email\Exceptions\EmailTransportException;
use App\Models\MailConfiguration;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

final class SmtpDiagnosticsService
{
    public function __construct(
        private readonly EmailTransport $transport,
        private readonly EmailSecretStore $secrets,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function sendTest(int $siteId, string $recipient): array
    {
        $this->authorizer->authorize('settings.manage');
        Site::query()->findOrFail($siteId);

        $recipient = trim($recipient);
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => 'Test recipient is not a valid email address.']);
        }

        $configuration = $this->resolveConfiguration($siteId);
        $secret = $this->secrets->get($configuration);
        $started = hrtime(true);
        $steps = [];

        $preflight = $this->transport->diagnose($configuration, $secret);
        $steps[] = [
            'name' => 'CONFIG',
            'is_success' => (bool) ($preflight['ok'] ?? false),
            'message' => $this->safe((string) ($preflight['message'] ?? 'SMTP configuration check failed.')),
        ];

        if (! ($preflight['ok'] ?? false)) {
            return $this->result(false, $steps[0]['message'], $steps, $started);
        }

        try {
            $this->transport->send($configuration, $secret, [
                'to' => $recipient,
                'subject' => 'AI WordPress Manager - SMTP test',
                'text' => 'This is a test email from AI WordPress Manager. Your saved SMTP configuration completed TLS/authentication and message submission successfully.',
                'html' => '<p>This is a test email from AI WordPress Manager. Your saved SMTP configuration completed TLS/authentication and message submission successfully.</p>',
            ]);

            $steps[] = [
                'name' => 'SMTP',
                'is_success' => true,
                'message' => 'Test message was accepted by the SMTP transport.',
            ];

            return $this->result(
                true,
                'Test email sent successfully. SMTP TLS/authentication and message submission passed.',
                $steps,
                $started,
            );
        } catch (EmailTransportException $exception) {
            $message = $this->safe($exception->getMessage());
            $steps[] = [
                'name' => $exception->category === 'AUTHENTICATION_CONFIG_FAILURE' ? 'TLS/AUTH' : 'SMTP',
                'is_success' => false,
                'message' => $message,
            ];

            return $this->result(false, $message, $steps, $started);
        }
    }

    private function resolveConfiguration(int $siteId): MailConfiguration
    {
        $siteConfiguration = MailConfiguration::query()
            ->where('configuration_key', "site:{$siteId}")
            ->first();

        if (! $siteConfiguration) {
            throw ValidationException::withMessages([
                'mail_profile' => 'Save the site mail profile before sending a test email.',
            ]);
        }

        $inherit = (bool) data_get($siteConfiguration->settings, 'use_account_profile', false);
        $configuration = $inherit
            ? MailConfiguration::query()->where('configuration_key', 'default')->first()
            : $siteConfiguration;

        if (! $configuration) {
            throw ValidationException::withMessages([
                'mail_profile' => 'The inherited account SMTP profile is not configured.',
            ]);
        }

        return $configuration;
    }

    private function result(bool $success, string $message, array $steps, int $started): array
    {
        return [
            'is_success' => $success,
            'message' => $this->safe($message),
            'duration_milliseconds' => max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
            'steps' => $steps,
        ];
    }

    private function safe(string $message): string
    {
        $redacted = preg_replace(
            '/(password|token|secret|api[_ -]?key)\s*[=:]\s*\S+/i',
            '$1=[REDACTED]',
            $message,
        );

        return mb_substr((string) ($redacted ?: 'SMTP test failed.'), 0, 1000);
    }
}
