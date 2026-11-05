<?php

namespace App\Sync;

use Throwable;

final class SyncDiagnosticFormatter
{
    /**
     * Diagnostics shown to an authorized site manager must be useful without
     * returning WordPress credentials, signed URLs or raw SQL parameters.
     */
    public static function sanitize(?string $raw): string
    {
        $message = trim((string) ($raw ?? ''));
        if ($message === '') {
            return 'No exception details were recorded; inspect the server log using the request ID.';
        }

        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? 'Invalid diagnostic encoding.';
        $message = preg_replace('~https?://[^\s<>"\x27]+~i', '[URL REDACTED]', $message) ?? $message;
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $message) ?? $message;
        $message = preg_replace(
            '/\b(password|passwd|pwd|token|secret|authorization|cookie|api[_-]?key|db_password|client_secret|access_key)\b\s*(?:=>|=|:)\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;&]+)/i',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;
        $message = preg_replace('/\b[A-Za-z0-9_+\/=-]{48,}\b/', '[REDACTED]', $message) ?? $message;
        $message = preg_replace('~\s*\(SQL:\s*.*$~is', ' [SQL statement withheld]', $message) ?? $message;
        $message = preg_replace('~(?:/home|/var/www|/etc)/[^\s,:()]+~', '[SERVER PATH]', $message) ?? $message;

        return mb_substr(trim($message), 0, 800);
    }

    public static function exception(Throwable $exception): string
    {
        return self::sanitize(class_basename($exception).': '.$exception->getMessage());
    }
}
