<?php

namespace App\Content\Remote;

use App\Connector\ConnectorResourceReader;
use App\Connector\WordPressGateway;
use App\Models\Connector;
use App\Models\Site;
use Illuminate\Support\Str;
use RuntimeException;

final class ConnectorSemanticPath
{
    private const SUPPORTED_UPDATE_FIELDS = ['title', 'content', 'slug'];

    public function __construct(private readonly ConnectorResourceReader $resources) {}

    public function list(int $siteId, string $resource, array $query = []): array
    {
        // Never route a read through /execute: the WordPress Connector only
        // authorizes approved mutations there, not a synthetic content.list.
        return $this->resources->listResource($this->site($siteId), $resource, $query);
    }

    public function get(int $siteId, string $resource, int $remoteId): array
    {
        return $this->resources->getResource($this->site($siteId), $resource, $remoteId);
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        // Connector v0.2.3's /execute accepts only existing post/page semantic
        // edits with the TOP-LEVEL resource_type, remote_id and changes keys.
        // It does not implement content.mutate, create, delete, publish or
        // arbitrary native WordPress REST fields. Refuse those before writing.
        abort_unless(
            in_array($resource, ['posts', 'pages'], true)
                && $action === 'update'
                && $remoteId !== null && $remoteId > 0,
            422,
            'This signed WordPress Connector only supports edits to existing posts and pages.',
        );
        abort_unless(
            $payload !== []
                && array_diff(array_keys($payload), self::SUPPORTED_UPDATE_FIELDS) === []
                && collect($payload)->every(static fn ($value): bool => is_string($value)),
            422,
            'Signed Connector edits support only text title, content and slug; use an authorized native WordPress REST connection for other changes.',
        );

        $site = $this->site($siteId);
        $connector = Connector::query()->where('site_id', $site->id)->firstOrFail();
        // Preflight BOTH scopes: an update must never be sent if the signed
        // verification GET cannot be performed. The gateway/WordPress still
        // independently enforce owner-enabled scope, identity and signatures.
        foreach (['content.update', 'content.read'] as $requiredScope) {
            if ($connector->revoked_at || ! in_array($requiredScope, $connector->enabled_scopes ?? [], true)) {
                throw new RuntimeException("Required connector scope is disabled: {$requiredScope}.");
            }
        }

        $receipt = $this->gateway()->execute($site, (string) Str::uuid(), [
            'resource_type' => $resource === 'posts' ? 'post' : 'page',
            'remote_id' => $remoteId,
            'changes' => $payload,
        ]);
        $after = $receipt['after'] ?? null;
        if (($receipt['status'] ?? null) !== 'succeeded' || ! is_array($after)
            || (int) ($after['id'] ?? 0) !== $remoteId
            || ($after['type'] ?? null) !== ($resource === 'posts' ? 'post' : 'page')) {
            throw new RuntimeException('Signed WordPress edit returned an unverified completion receipt; reconcile the remote record before retrying.');
        }

        // An acknowledged mutation is not the final truth. Do not update the
        // local read model until a separate signed, tenant-scoped GET proves
        // the intended content is persisted on the exact remote resource.
        $verified = $this->resources->getResource($site, $resource, $remoteId);
        if ((int) ($verified['id'] ?? 0) !== $remoteId
            || ($verified['type'] ?? null) !== ($resource === 'posts' ? 'post' : 'page')) {
            throw new RuntimeException('Signed WordPress edit readback returned an incorrect resource; reconcile before retrying.');
        }
        foreach ($payload as $field => $requested) {
            if (! array_key_exists($field, $verified) || ! is_string($verified[$field])
                || $verified[$field] !== $requested) {
                throw new RuntimeException('Signed WordPress edit readback differs from the requested content; reconcile before retrying.');
            }
        }

        return $verified;
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        return $this->execute($siteId, $operation, $payload);
    }

    private function execute(int $siteId, string $operation, array $payload): array
    {
        return $this->gateway()->execute($this->site($siteId), (string) Str::uuid(), ['operation' => $operation, 'payload' => $payload]);
    }

    private function gateway(): WordPressGateway
    {
        return app(WordPressGateway::class);
    }

    private function site(int $siteId): Site
    {
        return Site::query()->findOrFail($siteId);
    }
}
