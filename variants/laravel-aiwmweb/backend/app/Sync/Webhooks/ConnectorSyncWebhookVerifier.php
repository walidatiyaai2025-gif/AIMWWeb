<?php

namespace App\Sync\Webhooks;

use App\Sync\Contracts\SyncWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

final class ConnectorSyncWebhookVerifier implements SyncWebhookVerifier
{
    public function verify(Request $request): array
    {
        if (strlen($request->getContent()) > 262144) {
            throw new InvalidArgumentException('Webhook payload exceeds 256 KiB.');
        }

        $connectorClass = 'App\\Models\\Connector';
        $protocolClass = 'App\\Connector\\ConnectorProtocol';
        if (! class_exists($connectorClass) || ! class_exists($protocolClass)) {
            throw new RuntimeException('Canonical Connector webhook verifier is not integrated; webhook fails closed.');
        }

        $identity = (string) $request->header('X-AIMW-Connector');
        if ($identity === '') {
            throw new InvalidArgumentException('Connector identity is required.');
        }

        $connector = $connectorClass::withoutGlobalScopes()->where('identity', $identity)->first();
        if (! $connector) {
            throw new RuntimeException('Connector not found.');
        }

        $headers = [];
        foreach ([
            'X-AIMW-Version',
            'X-AIMW-Tenant',
            'X-AIMW-Site',
            'X-AIMW-Connector',
            'X-AIMW-Timestamp',
            'X-AIMW-Nonce',
            'X-AIMW-Request-ID',
            'X-AIMW-Correlation-ID',
            'X-AIMW-Operation-ID',
            'X-AIMW-Scope',
            'X-AIMW-Signature',
        ] as $name) {
            $headers[$name] = (string) $request->header($name);
        }

        if ($headers['X-AIMW-Scope'] !== 'content.read') {
            throw new RuntimeException('Webhook requires canonical content.read scope.');
        }

        app($protocolClass)->verifyInbound(
            $connector,
            $request->method(),
            '/api/v1/sync/webhooks/connector',
            $request->getContent(),
            $headers,
        );

        $payload = $request->validate([
            'event_id' => 'required|string|max:191',
            'event_type' => 'required|string|max:96',
            'occurred_at' => 'required|date',
            'resource' => 'required|string|in:posts,pages,media,categories,tags,comments',
            'remote_id' => 'required|integer|min:1',
            'action' => 'required|string|in:created,updated,deleted,delete',
            'payload' => 'sometimes|array',
        ]);

        if ((int) $headers['X-AIMW-Tenant'] !== (int) $connector->tenant_id || (int) $headers['X-AIMW-Site'] !== (int) $connector->site_id) {
            throw new RuntimeException('Connector tenant/site identity mismatch.');
        }

        $occurredAt = Carbon::parse($payload['occurred_at'], 'UTC');
        if ($occurredAt->lt(now()->subDay()) || $occurredAt->gt(now()->addMinutes(5))) {
            throw new RuntimeException('Webhook event timestamp is outside the accepted window.');
        }

        return [
            'tenant_id' => (int) $connector->tenant_id,
            'site_id' => (int) $connector->site_id,
            'connector_id' => (int) $connector->id,
            'event_id' => $payload['event_id'],
            'event_type' => $payload['event_type'],
            'occurred_at' => $occurredAt,
            'resource' => $payload['resource'],
            'remote_id' => (int) $payload['remote_id'],
            'action' => $payload['action'],
            'payload' => $payload['payload'] ?? [],
        ];
    }
}
