<?php

namespace App\Content\Remote;

use App\Connector\ConnectorResourceReader;

use Illuminate\Support\Str;
use RuntimeException;

final class ConnectorSemanticPath
{
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
        return $this->execute($siteId, 'content.mutate', compact('resource', 'remoteId', 'action', 'payload'));
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        return $this->execute($siteId, $operation, $payload);
    }

    private function execute(int $siteId, string $operation, array $payload): array
    {
        return $this->gateway()->execute($this->site($siteId), (string) Str::uuid(), ['operation' => $operation, 'payload' => $payload]);
    }

    private function gateway(): object
    {
        $contract = 'App\\Connector\\WordPressGateway';
        if (! interface_exists($contract)) {
            throw new RuntimeException('Connector semantic gateway is not integrated yet.');
        }

        return app($contract);
    }

    private function site(int $siteId): object
    {
        $class = 'App\\Models\\Site';
        if (! class_exists($class)) {
            throw new RuntimeException('Site model is not integrated yet.');
        }

        return $class::query()->findOrFail($siteId);
    }
}
