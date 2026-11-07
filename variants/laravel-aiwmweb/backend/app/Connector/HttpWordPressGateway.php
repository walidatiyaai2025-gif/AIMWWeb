<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWordPressGateway implements AdvancedWordPressGateway, ConnectorResourceReader
{
    public function __construct(private readonly ConnectorProtocol $protocol, private readonly ConnectorScopePolicy $scopes) {}

    public function health(Site $site): array
    {
        return $this->request($site, 'GET', '/wp-json/aimw/v1/health', 'health');
    }

    public function capabilities(Site $site): array
    {
        return $this->request($site, 'GET', '/wp-json/aimw/v1/capabilities', 'health');
    }

    public function content(Site $site, ?string $modifiedAfter = null): array
    {
        $path = '/wp-json/aimw/v1/content'.($modifiedAfter ? '?modified_after='.rawurlencode($modifiedAfter) : '');

        return $this->request($site, 'GET', $path, 'content.read');
    }

    private const READ_RESOURCES = ['posts', 'pages', 'media', 'categories', 'tags', 'comments'];

    public function listResource(Site $site, string $resource, array $query = []): array
    {
        $this->assertReadResource($resource);
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 100);
        abort_unless($page >= 1 && $page <= 1000 && $perPage >= 1 && $perPage <= 100, 422, 'Invalid WordPress content pagination.');

        $parameters = ['page' => $page, 'per_page' => $perPage];
        if (! empty($query['modified_after'])) {
            $parameters['modified_after'] = (string) $query['modified_after'];
        }

        $path = '/wp-json/aimw/v1/content/resources/'.$resource.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $payload = $this->request($site, 'GET', $path, 'content.read');
        $this->assertSuccessfulResourcePayload($payload);
        if (! isset($payload['items']) || ! is_array($payload['items']) || ! array_is_list($payload['items'])
            || count($payload['items']) > $perPage) {
            throw new RuntimeException('Connector resource read returned an invalid items contract.');
        }

        // A malformed HTTP 200 resource page must fail before any full-sync
        // missing-record reconciliation observes it as an empty/short page.
        foreach ($payload['items'] as $row) {
            if (! is_array($row) || array_is_list($row)
                || ! isset($row['id'])
                || filter_var($row['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException('Connector resource read returned a malformed resource record.');
            }
        }

        return $payload['items'];
    }

    public function getResource(Site $site, string $resource, int $remoteId): array
    {
        $this->assertReadResource($resource);
        abort_unless($remoteId > 0, 422, 'Invalid WordPress resource ID.');

        $payload = $this->request($site, 'GET', "/wp-json/aimw/v1/content/resources/{$resource}/{$remoteId}", 'content.read');
        $this->assertSuccessfulResourcePayload($payload);
        if (filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== $remoteId) {
            throw new RuntimeException('Connector resource read returned an incorrect resource ID.');
        }

        return $payload;
    }

    /**
     * WordPress REST errors use non-2xx statuses. Reverse proxies can turn an
     * upstream error into HTTP 200 while leaving an error-shaped JSON body.
     * Never let such a response become an authoritative empty sync page.
     */
    private function assertSuccessfulResourcePayload(array $payload): void
    {
        if (($payload['success'] ?? true) === false
            || (array_key_exists('error', $payload) && $payload['error'] !== null
                && $payload['error'] !== false && $payload['error'] !== '')
            || in_array(strtolower((string) ($payload['status'] ?? '')), ['failed', 'failure', 'error'], true)) {
            throw new RuntimeException('Connector resource read returned an error-marked response.');
        }
    }

    private function assertReadResource(string $resource): void
    {
        abort_unless(in_array($resource, self::READ_RESOURCES, true), 422, 'Unsupported WordPress resource.');
    }

    public function execute(Site $site, string $operationId, array $change): array
    {
        $required = $this->scopes->requiredFor('content.execute', $change);

        return $this->request($site, 'POST', '/wp-json/aimw/v1/execute', end($required), $change, $operationId, $required);
    }

    public function operate(Site $site, string $operationId, string $operation, array $arguments = []): array
    {
        $payload = ['operation' => $operation, 'arguments' => $arguments];
        $required = $this->scopes->requiredFor('connector.operate', $payload);

        return $this->request($site, 'POST', '/wp-json/aimw/v1/operate', end($required), $payload, $operationId, $required);
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
            $message = data_get($response->json(), 'message');
            throw new RuntimeException(is_string($message) && $message !== '' ? $message : "Connector request failed ({$response->status()}).");
        }

        return $response->json() ?? [];
    }
}
