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
