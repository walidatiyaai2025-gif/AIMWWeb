<?php

namespace App\Operations;

final class Redactor
{
    private const SENSITIVE = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey',
        'private_key', 'client_secret', 'access_token', 'refresh_token',
    ];

    public function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $clean[$key] = $this->isSensitive($normalized) ? '[REDACTED]' : $this->redact($item);
        }

        return $clean;
    }

    /**
     * Protect the Logs API from historical/imported free-text messages that
     * bypassed structured write-time redaction. Preserve non-secret context
     * and never modify the stored source row.
     */
    public function redactLogMessage(string $message): string
    {
        $redacted = preg_replace(
            '/\b(authorization\s*:\s*(?:bearer|basic)\s+|(?:x[-_]?api[-_]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|password|client[_-]?secret)\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '$1[REDACTED]',
            $message,
        ) ?? '[REDACTED]';

        return preg_replace('/\bsk-[A-Za-z0-9_-]{12,}\b/', '[REDACTED]', $redacted) ?? '[REDACTED]';
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE as $needle) {
            if ($key === $needle || str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
