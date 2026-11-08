<?php

namespace App\Sync;

use Illuminate\Http\Client\ConnectionException;
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

        // Include common camelCase credential keys emitted by REST/SDK clients.
        // Upstream errors sometimes embed JSON or PHP array dumps. Quoted
        // credential keys bypass the bare-key regex below. Truncate from the
        // first quoted secret key instead of parsing untrusted escaped values:
        // malformed JSON must not expose the tail of a credential or payload.
        $quotedSecretKey = <<<'REGEX'
~(["'])(password|passwd|pwd|token|secret|authorization|cookie|api[_-]?key|x[_-]?api[_-]?key|db_password|client[_-]?secret|access[_-]?key|access[_-]?token|refresh[_-]?token|shared[_-]?secret|secret[_-]?key|apiKey|accessToken|refreshToken|clientSecret|secretKey|sharedSecret|dbPassword|authToken|sessionToken)\1\s*(?:=>|=|:)\s*.*$~is
REGEX;
        $message = preg_replace($quotedSecretKey, '$2=[REDACTED]', $message) ?? $message;
        $message = preg_replace(
            '/\b(password|passwd|pwd|token|secret|authorization|cookie|api[_-]?key|x[_-]?api[_-]?key|db_password|client[_-]?secret|access[_-]?key|access[_-]?token|refresh[_-]?token|shared[_-]?secret|secret[_-]?key|apiKey|accessToken|refreshToken|clientSecret|secretKey|sharedSecret|dbPassword|authToken|sessionToken)\b\s*(?:=>|=|:)\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;&]+)/i',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;
        $message = preg_replace('/\b[A-Za-z0-9_+\/=-]{48,}\b/', '[REDACTED]', $message) ?? $message;
        $message = preg_replace('~\s*\(SQL:\s*.*$~is', ' [SQL statement withheld]', $message) ?? $message;
        $message = preg_replace('~(?:/home|/var/www|/etc)/[^\s,:()]+~', '[SERVER PATH]', $message) ?? $message;

        return mb_substr(trim($message), 0, 800);
    }

    /**
     * Store only stable, user-actionable failure categories in operation history.
     * Never copy raw WordPress error bodies, URLs or credentials into its details.
     *
     * @return array{failure_code: string, failure_category: string, failure_message: string}
     */
    public static function historyFailure(Throwable $exception): array
    {
        $message = strtolower($exception->getMessage());
        $category = match (true) {
            preg_match('/\b(401|403|unauthorized|forbidden|authentication|signature)\b/', $message) === 1 => 'authentication',
            preg_match('/\b(429|quota|rate.limit(?:ed)?)\b/', $message) === 1 => 'rate_limit',
            preg_match('/\b(invalid.protocol|protocol mismatch|version mismatch)\b/', $message) === 1 => 'protocol',
            $exception instanceof ConnectionException,
            preg_match('/\b(timeout|timed out|connection refused|dns|curl|socket|tls)\b/', $message) === 1 => 'transport',
            preg_match('/\b(5[0-9]{2}|server error|service unavailable)\b/', $message) === 1 => 'remote_service',
            preg_match('/\b(sqlstate|database|disk full)\b/', $message) === 1 => 'storage',
            default => 'unknown',
        };

        return [
            'failure_code' => 'WP_SYNC_'.strtoupper($category),
            'failure_category' => $category,
            'failure_message' => match ($category) {
                'authentication' => 'WordPress rejected the connection. Recheck Connector credentials and signed request permissions.',
                'rate_limit' => 'WordPress or a provider reported a limit. Check quotas and retry when permitted.',
                'protocol' => 'The Connector protocol was not accepted. Verify both installed Connector and Laravel versions.',
                'transport' => 'The WordPress endpoint could not be reached reliably. Check network and TLS connectivity.',
                'remote_service' => 'WordPress returned a server-side error. Check the remote service health before retrying.',
                'storage' => 'Synchronization encountered a database or storage failure. Check server health and free capacity.',
                default => 'Synchronization failed for an unclassified reason. Open the authorized run details for diagnosis.',
            },
        ];
    }

    public static function exception(Throwable $exception): string
    {
        return self::sanitize(class_basename($exception).': '.$exception->getMessage());
    }
}
