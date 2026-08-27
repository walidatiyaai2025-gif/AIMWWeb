<?php

namespace App\Content\Remote;

interface ContentRemoteDriver
{
    public function list(int $siteId, string $resource, array $query = []): array;
    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array;
    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array;
    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array;
    public function semantic(int $siteId, string $operation, array $payload = []): array;
}
