<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWordPressGateway implements WordPressGateway
{
    public function __construct(private readonly ConnectorProtocol $protocol, private readonly ConnectorScopePolicy $scopes) {}

    public function health(Site $site): array
    {
        return $this->request($site, 'GET', '/wp-json/aimw/v1/health', 'health');
    }

    public function content(Site $site, ?string $modifiedAfter = null): array
    {
        $path = '/wp-json/aimw/v1/content'.($modifiedAfter ? '?modified_after='.rawurlencode($modifiedAfter) : '');

        return $this->request($site, 'GET', $path, 'content.read');
    }

    public function execute(Site $site, string $operationId, array $change): array
    {
        $required = $this->scopes->requiredFor('content.execute', $change);

        return $this->request($site, 'POST', '/wp-json/aimw/v1/execute', end($required), $change, $operationId, $required);
    }

    public function read(Site $site, string $type, int $remoteId): array
    {
        return $this->request($site, 'GET', "/wp-json/aimw/v1/content/{$type}/{$remoteId}", 'content.read');
    }

    public function rotateSecret(Site $site, string $newSecret): array
    {
        return $this->request($site, 'POST', '/wp-json/aimw/v1/rotate', 'connector.manage', ['new_secret' => $newSecret]);
    }

    public function disconnect(Site $site): array
    {
        return $this->request($site, 'POST', '/wp-json/aimw/v1/disconnect', 'connector.manage');
    }

    private function request(Site $site, string $method, string $path, string $scope, array $payload = [], ?string $operationId = null, ?array $requiredScopes = null): array
    {
        $connector = Connector::query()->where('site_id', $site->id)->firstOrFail();
        if ($connector->revoked_at) {
            throw new RuntimeException('Connector is revoked.');
        }
        foreach ($requiredScopes ?? [$scope] as $required) {
            if (! in_array($required, $connector->enabled_scopes, true)) {
                throw new RuntimeException("Required connector scope is disabled: {$required}.");
            }
        }
        $body = $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $headers = $this->protocol->sign($connector, $method, $path, $body, $scope, $operationId);
        $response = Http::timeout(30)->withHeaders($headers)->withBody($body, 'application/json')->send($method, rtrim($site->url, '/').$path);
        if (! $response->successful()) {
            throw new RuntimeException("Connector request failed ({$response->status()}).");
        }

        return $response->json() ?? [];
    }
}
