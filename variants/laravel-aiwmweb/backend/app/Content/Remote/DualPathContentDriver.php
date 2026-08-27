<?php

namespace App\Content\Remote;

final class DualPathContentDriver implements ContentRemoteDriver
{
    public function __construct(private readonly NativeWordPressRestPath $rest, private readonly ConnectorSemanticPath $connector) {}

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return $this->rest->available($siteId) ? $this->rest->list($siteId, $resource, $query) : $this->connector->list($siteId, $resource, $query);
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        return $this->rest->available($siteId) && in_array($resource, ['posts','pages','media','comments','categories','tags'], true)
            ? $this->rest->get($siteId, $resource, $remoteId, $query)
            : $this->connector->get($siteId, $resource, $remoteId);
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        return $this->rest->available($siteId) && in_array($resource, ['posts','pages','media','comments','categories','tags'], true)
            ? $this->rest->mutate($siteId, $resource, $remoteId, $action, $payload)
            : $this->connector->mutate($siteId, $resource, $remoteId, $action, $payload);
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        return $this->rest->available($siteId)
            ? $this->rest->upload($siteId, $path, $name, $mimeType, $metadata)
            : $this->connector->semantic($siteId, 'media.upload', ['path'=>$path,'name'=>$name,'mime_type'=>$mimeType,'metadata'=>$metadata]);
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        return $this->connector->semantic($siteId, $operation, $payload);
    }
}
