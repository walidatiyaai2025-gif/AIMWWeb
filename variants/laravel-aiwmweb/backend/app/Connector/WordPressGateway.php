<?php

namespace App\Connector;

use App\Models\Site;

interface WordPressGateway
{
    public function health(Site $site): array;

    public function content(Site $site, ?string $modifiedAfter = null): array;

    public function execute(Site $site, string $operationId, array $change): array;

    public function read(Site $site, string $type, int $remoteId): array;

    public function rotateSecret(Site $site, string $newSecret): array;

    public function disconnect(Site $site): array;
}
