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
     * Sanitize historical operation-log context at the API read boundary.
     * Old rows may contain credential-bearing text under harmless field names.
     * Never modify the persisted row or expose deeply nested untrusted values.
     */
    public function redactLogContext(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? $this->redactLogMessage($value) : $value;
        }

        if ($depth >= 5) {
            return array_fill_keys(array_keys($value), '[REDACTED]');
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $clean[$key] = $this->isSensitive($normalized)
                ? '[REDACTED]'
                : $this->redactLogContext($item, $depth + 1);
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
        // Credential values in quoted JSON keys are not captured by the
        // free-text key=value matcher below. Preserve valid JSON syntax and
        // avoid ever returning the credential text to a log sink.
        $message = preg_replace(
            '/("(?:x[-_]?api[-_]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|password|passwd|client[_-]?secret|private[_-]?key|authorization|cookie|token|secret)"\\s*:\\s*")((?:\\\\\\\\.|[^"\\\\\\\\])*)(")/i',
            '$1[REDACTED]$3',
            $message,
        ) ?? '[REDACTED]';

        $redacted = preg_replace(
            '/\b(authorization\s*:\s*(?:bearer|basic)\s+|(?:x[-_]?api[-_]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|password|passwd|private[_-]?key|client[_-]?secret|cookie|token|secret)\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
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
