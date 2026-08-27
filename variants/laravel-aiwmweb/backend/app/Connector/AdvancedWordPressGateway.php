<?php

namespace App\Connector;

use App\Models\Site;

interface AdvancedWordPressGateway extends WordPressGateway
{
    public function capabilities(Site $site): array;

    public function operate(Site $site, string $operationId, string $operation, array $arguments = []): array;
}
