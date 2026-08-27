<?php

namespace App\Content\Remote;

use RuntimeException;

final class ConnectorSemanticPath
{
    public function list(int $siteId, string $resource, array $query = []): array
    {
        if (in_array($resource, ['posts','pages','media','categories','tags'], true) && empty($query)) {
            $all = $this->gateway()->content($this->site($siteId));
            return data_get($all, $resource, data_get($all, 'data.'.$resource, [])) ?: [];
        }
        return data_get($this->execute($siteId, 'content.list', compact('resource','query')), 'items', []);
    }

    public function get(int $siteId, string $resource, int $remoteId): array
    {
        $type = match ($resource) { 'posts'=>'post','pages'=>'page', default=>$resource };
        return $this->gateway()->read($this->site($siteId), $type, $remoteId);
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        return $this->execute($siteId, 'content.mutate', compact('resource','remoteId','action','payload'));
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        return $this->execute($siteId, $operation, $payload);
    }

    private function execute(int $siteId, string $operation, array $payload): array
    {
        return $this->gateway()->execute($this->site($siteId), (string) \Illuminate\Support\Str::uuid(), ['operation'=>$operation,'payload'=>$payload]);
    }

    private function gateway(): object
    {
        $contract = 'App\\Connector\\WordPressGateway';
        if (! interface_exists($contract)) throw new RuntimeException('Connector semantic gateway is not integrated yet.');
        return app($contract);
    }

    private function site(int $siteId): object
    {
        $class = 'App\\Models\\Site';
        if (! class_exists($class)) throw new RuntimeException('Site model is not integrated yet.');
        return $class::query()->findOrFail($siteId);
    }
}
