<?php

namespace App\Connector;

use RuntimeException;

final class ConnectorScopePolicy
{
    public function requiredFor(string $operation, array $payload = []): array
    {
        return match ($operation) {
            'health', 'capabilities' => ['health'],
            'history' => ['audit.local'],
            'content.list', 'content.read' => ['content.read'],
            'content.execute' => $this->mutationScopes($payload),
            'connector.rotate', 'connector.disconnect' => ['connector.manage'],
            'connector.operate' => $this->advancedScopes($payload),
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

    private function advancedScopes(array $payload): array
    {
        $operation = (string) ($payload['operation'] ?? '');

        return match ($operation) {
            'adapters.list' => ['adapters.read'],
            'plugins.list' => ['plugins.read'],
            'plugin.install', 'plugin.activate', 'plugin.deactivate', 'plugin.update', 'plugin.delete' => ['plugins.manage'],
            'themes.list' => ['themes.read'],
            'theme.install', 'theme.activate', 'theme.update', 'theme.delete' => ['themes.manage'],
            'cache.purge' => ['cache.manage'],
            'cron.list', 'cron.inspect' => ['cron.read'],
            'cron.run_due', 'cron.run' => ['cron.manage'],
            'site.health' => ['diagnostics.read'],
            'backup.list', 'backup.inspect' => ['backup.read'],
            'backup.create' => ['backup.create'],
            'backup.restore' => ['backup.restore'],
            'filesystem.inspect' => ['filesystem.read'],
            'database.health' => ['database.read'],
            'database.optimize' => ['database.manage'],
            default => throw new RuntimeException('Unknown connector semantic operation.'),
        };
    }
}
