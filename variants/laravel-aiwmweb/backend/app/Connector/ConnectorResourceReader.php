<?php

namespace App\Connector;

use App\Models\Site;

/**
 * Read-only, signed AIMW Connector resources.
 *
 * Kept separate from WordPressGateway to preserve existing SEO and pairing
 * contracts while preventing content list reads from using /execute.
 */
interface ConnectorResourceReader
{
    /** @return array<int,array<string,mixed>> */
    public function listResource(Site $site, string $resource, array $query = []): array;

    /** @return array<string,mixed> */
    public function getResource(Site $site, string $resource, int $remoteId): array;
}
