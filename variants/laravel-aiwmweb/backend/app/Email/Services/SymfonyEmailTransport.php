<?php

namespace App\Email\Services;

use App\Email\Contracts\EmailTransport;
use App\Email\Exceptions\EmailTransportException;
use App\Models\MailConfiguration;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;

final class SymfonyEmailTransport implements EmailTransport
{
    public function send(MailConfiguration $configuration, ?string $secret, array $message): array
    {
        $this->validateConfiguration($configuration, $secret);

        try {
            $mailer = new Mailer(Transport::fromDsn($this->dsn($configuration, $secret)));
            $email = (new Email)
                ->from($configuration->from_address)
                ->to($message['to'])
                ->subject($message['subject'])
                ->text($message['text'])
                ->html($message['html']);
            if (filled($configuration->reply_to)) {
                $email->replyTo($configuration->reply_to);
            }
            $mailer->send($email);

            return ['provider_message_id' => $email->getHeaders()->get('Message-ID')?->getBodyAsString()];
        } catch (Throwable $exception) {
            throw $this->classify($exception);
        }
    }

    public function diagnose(MailConfiguration $configuration, ?string $secret): array
    {
        try {
            $this->validateConfiguration($configuration, $secret);
            Transport::fromDsn($this->dsn($configuration, $secret));

            return ['ok' => true, 'message' => 'SMTP configuration is syntactically valid.'];
        } catch (EmailTransportException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'SMTP configuration could not be initialized.'];
        }
    }

    private function validateConfiguration(MailConfiguration $configuration, ?string $secret): void
    {
        if (! $configuration->enabled || blank($configuration->host) || blank($configuration->from_address)) {
            throw new EmailTransportException('AUTHENTICATION_CONFIG_FAILURE', false, 'Email transport is not configured.');
        }
        if (! filter_var($configuration->from_address, FILTER_VALIDATE_EMAIL)) {
            throw new EmailTransportException('AUTHENTICATION_CONFIG_FAILURE', false, 'Sender address is invalid.');
        }
        if (filled($configuration->username) && blank($secret)) {
            throw new EmailTransportException('AUTHENTICATION_CONFIG_FAILURE', false, 'SMTP credential is missing.');
        }
    }

    private function dsn(MailConfiguration $configuration, ?string $secret): string
    {
        $scheme = $configuration->encryption === 'ssl' ? 'smtps' : 'smtp';
        $auth = '';
        if (filled($configuration->username)) {
            $auth = rawurlencode((string) $configuration->username).':'.rawurlencode((string) $secret).'@';
        }
        $query = $configuration->encryption === 'tls' ? '?require_tls=true' : '';

        return "{$scheme}://{$auth}{$configuration->host}:{$configuration->port}{$query}";
    }

    private function classify(Throwable $exception): EmailTransportException
    {
        $message = preg_replace('/(password|token|secret|api[_ -]?key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $exception->getMessage()) ?: 'Email transport failed.';
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'timed out'), str_contains($lower, 'timeout') => new EmailTransportException('TIMEOUT', true, $message),
            str_contains($lower, '429'), str_contains($lower, 'rate limit') => new EmailTransportException('RATE_LIMIT', true, $message, 120),
            str_contains($lower, '535'), str_contains($lower, 'authentication') => new EmailTransportException('AUTHENTICATION_CONFIG_FAILURE', false, $message),
            str_contains($lower, 'recipient'), str_contains($lower, 'mailbox unavailable'), str_contains($lower, '550') => new EmailTransportException('INVALID_RECIPIENT', false, $message),
            str_contains($lower, '554'), str_contains($lower, 'rejected') => new EmailTransportException('PERMANENT_REJECTION', false, $message),
            default => new EmailTransportException('TEMPORARY_PROVIDER_FAILURE', true, $message),
        };
    }
}
