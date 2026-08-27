<?php

namespace App\Connector;

use RuntimeException;

final class ConnectorScopePolicy
{
    public function requiredFor(string $operation, array $payload = []): array
    {
        return match ($operation) {
            'health' => ['health'],
            'content.list', 'content.read' => ['content.read'],
            'content.execute' => $this->mutationScopes($payload),
            'connector.rotate', 'connector.disconnect' => ['connector.manage'],
            default => throw new RuntimeException('Unknown connector operation.'),
        };
    }

    public function assertAuthorized(string $operation, array $payload, array $enabledScopes): void
    {
        foreach ($this->requiredFor($operation, $payload) as $scope) {
            if (! in_array($scope, $enabledScopes, true)) {
                throw new RuntimeException("Required connector scope is disabled: {$scope}.");
            }
        }
    }

    private function mutationScopes(array $payload): array
    {
        $changes = (array) ($payload['changes'] ?? []);
        $required = [];
        if (array_intersect(['title', 'content', 'slug'], array_keys($changes))) {
            $required[] = 'content.update';
        }
        if (array_intersect(['seo_title', 'seo_description'], array_keys($changes))) {
            $required[] = 'seo.write';
        }
        if ($required === []) {
            throw new RuntimeException('Mutation contains no supported semantic changes.');
        }

        return $required;
    }
}
