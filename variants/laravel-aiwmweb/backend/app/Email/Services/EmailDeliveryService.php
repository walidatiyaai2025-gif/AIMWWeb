<?php

namespace App\Email\Services;

use App\Email\Contracts\EmailTransport;
use App\Email\Exceptions\EmailTransportException;
use App\Jobs\SendEmailDeliveryJob;
use App\Models\EmailDelivery;
use App\Models\MailConfiguration;
use App\Services\AuditLogger;
use App\Tenancy\TenantLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EmailDeliveryService
{
    public function __construct(
        private readonly EmailTemplateService $templates,
        private readonly EmailSecretStore $secrets,
        private readonly EmailTransport $transport,
        private readonly AuditLogger $audit,
        private readonly TenantLock $lock,
    ) {}

    public function queue(array $input, bool $suppressed = false): EmailDelivery
    {
        $recipient = trim((string) ($input['recipient'] ?? ''));
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => 'Recipient email address is invalid.']);
        }

        $delivery = EmailDelivery::query()->firstOrCreate(
            ['idempotency_key' => (string) $input['idempotency_key']],
            [
                'in_app_notification_id' => $input['in_app_notification_id'] ?? null,
                'mail_configuration_id' => $input['mail_configuration_id'] ?? null,
                'event_id' => (string) $input['event_id'],
                'delivery_id' => (string) Str::uuid(),
                'recipient' => $recipient,
                'recipient_hash' => hash('sha256', strtolower($recipient)),
                'template_stable_id' => (string) $input['template_stable_id'],
                'locale' => $input['locale'] ?? 'en',
                'status' => $suppressed ? 'SUPPRESSED' : 'QUEUED',
                'attempt_count' => 0,
                'max_attempts' => min(max((int) ($input['max_attempts'] ?? 4), 1), 5),
                'variables' => (array) ($input['variables'] ?? []),
                'scheduled_for' => $input['scheduled_for'] ?? null,
            ],
        );

        if (! $delivery->wasRecentlyCreated || $suppressed) {
            return $delivery;
        }

        $job = new SendEmailDeliveryJob($delivery->tenant_id, $delivery->id);
        if ($delivery->scheduled_for?->isFuture()) {
            $job->delay($delivery->scheduled_for);
        }
        dispatch($job);

        return $delivery;
    }

    /** @return array{retry:bool,delay:int} */
    public function send(int $deliveryId): array
    {
        return $this->lock->block(
            "email-delivery:{$deliveryId}",
            65,
            fn (): array => $this->sendLocked($deliveryId),
        );
    }

    /** @return array{retry:bool,delay:int} */
    private function sendLocked(int $deliveryId): array
    {
        $delivery = DB::transaction(function () use ($deliveryId): EmailDelivery {
            $locked = EmailDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
            if (in_array($locked->status, ['SENT', 'SUPPRESSED', 'FAILED'], true)) {
                return $locked;
            }
            $locked->update([
                'status' => 'SENDING',
                'attempt_count' => $locked->attempt_count + 1,
                'sending_started_at' => now(),
                'failure_category' => null,
                'failure_message' => null,
            ]);

            return $locked->fresh();
        });

        if (in_array($delivery->status, ['SENT', 'SUPPRESSED', 'FAILED'], true)) {
            return ['retry' => false, 'delay' => 0];
        }
        $configuration = $delivery->mail_configuration_id
            ? MailConfiguration::query()->find($delivery->mail_configuration_id)
            : MailConfiguration::query()->where('configuration_key', 'default')->where('enabled', true)->first();
        if (! $configuration) {
            return $this->recordFailure($delivery, new EmailTransportException('AUTHENTICATION_CONFIG_FAILURE', false, 'No enabled mail configuration is available.'));
        }

        try {
            $rendered = $this->templates->render($delivery->template_stable_id, $delivery->locale, $delivery->variables ?? []);
            $response = $this->transport->send($configuration, $this->secrets->get($configuration), [...$rendered, 'to' => $delivery->recipient]);
            $delivery->update([
                'status' => 'SENT',
                'provider_message_id' => $response['provider_message_id'] ?? null,
                'sent_at' => now(),
                'failed_at' => null,
            ]);
            $this->audit->record('email.delivery.sent', [
                'delivery_id' => $delivery->delivery_id,
                'template' => $delivery->template_stable_id,
                'recipient_hash' => $delivery->recipient_hash,
                'attempt' => $delivery->attempt_count,
            ], EmailDelivery::class, $delivery->id);

            return ['retry' => false, 'delay' => 0];
        } catch (EmailTransportException $exception) {
            return $this->recordFailure($delivery, $exception);
        }
    }

    public function history(): array
    {
        return EmailDelivery::query()->latest()->limit(250)->get()->map(fn (EmailDelivery $delivery) => [
            'delivery_id' => $delivery->delivery_id,
            'notification_id' => $delivery->in_app_notification_id,
            'recipient' => $this->mask((string) $delivery->recipient),
            'template' => $delivery->template_stable_id,
            'status' => $delivery->status,
            'attempt' => $delivery->attempt_count,
            'provider_message_id' => $delivery->provider_message_id,
            'failure_category' => $delivery->failure_category,
            'failure_message' => $delivery->failure_message,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
        ])->all();
    }

    private function recordFailure(EmailDelivery $delivery, EmailTransportException $exception): array
    {
        $retry = $exception->retryable && $delivery->attempt_count < $delivery->max_attempts;
        $delay = $exception->retryAfterSeconds ?? [1 => 30, 2 => 120, 3 => 600, 4 => 1800][$delivery->attempt_count] ?? 1800;
        $safe = preg_replace('/(password|token|secret|api[_ -]?key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $exception->getMessage());
        $delivery->update([
            'status' => $retry ? 'RETRYING' : 'FAILED',
            'failure_category' => $exception->category,
            'failure_message' => Str::limit((string) $safe, 1000, ''),
            'failed_at' => $retry ? null : now(),
        ]);
        $this->audit->record('email.delivery.failed', [
            'delivery_id' => $delivery->delivery_id,
            'failure_category' => $exception->category,
            'retrying' => $retry,
            'attempt' => $delivery->attempt_count,
        ], EmailDelivery::class, $delivery->id);

        return ['retry' => $retry, 'delay' => min(max($delay, 15), 1800)];
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '[redacted]';
        }

        return Str::substr($local, 0, 1).'***@'.$domain;
    }
}
