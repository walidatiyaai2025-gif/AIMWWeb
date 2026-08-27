<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\ConnectorNonce;
use Illuminate\Support\Str;
use RuntimeException;

final class ConnectorProtocol
{
    public const VERSION = '1';

    public const CLOCK_SKEW_SECONDS = 300;

    public function sign(Connector $connector, string $method, string $path, string $body, string $scope, ?string $operationId = null): array
    {
        $headers = [
            'X-AIMW-Version' => self::VERSION,
            'X-AIMW-Tenant' => (string) $connector->tenant_id,
            'X-AIMW-Site' => (string) $connector->site_id,
            'X-AIMW-Connector' => $connector->identity,
            'X-AIMW-Timestamp' => (string) now()->timestamp,
            'X-AIMW-Nonce' => Str::random(40),
            'X-AIMW-Request-ID' => (string) Str::uuid(),
            'X-AIMW-Correlation-ID' => (string) Str::uuid(),
            'X-AIMW-Operation-ID' => $operationId ?? (string) Str::uuid(),
            'X-AIMW-Scope' => $scope,
        ];
        $headers['X-AIMW-Signature'] = hash_hmac('sha256', $this->canonical($method, $path, $body, $headers), $connector->encrypted_secret);

        return $headers;
    }

    public function verifyInbound(Connector $connector, string $method, string $path, string $body, array $headers): void
    {
        if ($connector->revoked_at) {
            throw new RuntimeException('Connector is revoked.');
        }
        foreach (['X-AIMW-Version', 'X-AIMW-Tenant', 'X-AIMW-Site', 'X-AIMW-Connector', 'X-AIMW-Timestamp', 'X-AIMW-Nonce', 'X-AIMW-Request-ID', 'X-AIMW-Correlation-ID', 'X-AIMW-Operation-ID', 'X-AIMW-Scope', 'X-AIMW-Signature'] as $required) {
            if (! isset($headers[$required])) {
                throw new RuntimeException("Missing protocol header: {$required}");
            }
        }
        if ($headers['X-AIMW-Version'] !== self::VERSION || (string) $connector->tenant_id !== $headers['X-AIMW-Tenant'] || (string) $connector->site_id !== $headers['X-AIMW-Site'] || $connector->identity !== $headers['X-AIMW-Connector']) {
            throw new RuntimeException('Connector identity or protocol version mismatch.');
        }
        if (abs(now()->timestamp - (int) $headers['X-AIMW-Timestamp']) > self::CLOCK_SKEW_SECONDS) {
            throw new RuntimeException('Connector timestamp expired.');
        }
        if (! in_array($headers['X-AIMW-Scope'], $connector->enabled_scopes, true)) {
            throw new RuntimeException('Connector scope is disabled.');
        }
        $expected = hash_hmac('sha256', $this->canonical($method, $path, $body, $headers), $connector->encrypted_secret);
        if (! hash_equals($expected, $headers['X-AIMW-Signature'])) {
            throw new RuntimeException('Invalid connector signature.');
        }
        ConnectorNonce::query()->create([
            'connector_id' => $connector->id,
            'nonce' => $headers['X-AIMW-Nonce'],
            'expires_at' => now()->addSeconds(self::CLOCK_SKEW_SECONDS),
        ]);
    }

    private function canonical(string $method, string $path, string $body, array $headers): string
    {
        return implode("\n", [strtoupper($method), $path, hash('sha256', $body), $headers['X-AIMW-Version'], $headers['X-AIMW-Tenant'], $headers['X-AIMW-Site'], $headers['X-AIMW-Connector'], $headers['X-AIMW-Timestamp'], $headers['X-AIMW-Nonce'], $headers['X-AIMW-Request-ID'], $headers['X-AIMW-Correlation-ID'], $headers['X-AIMW-Operation-ID'], $headers['X-AIMW-Scope']]);
    }
}
