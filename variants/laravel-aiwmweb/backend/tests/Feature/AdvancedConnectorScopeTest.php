<?php

namespace Tests\Feature;

use App\Connector\ConnectorScopePolicy;
use App\Connector\PairingService;
use RuntimeException;
use Tests\TestCase;

class AdvancedConnectorScopeTest extends TestCase
{
    public function test_advanced_semantic_operations_are_bound_to_explicit_scopes(): void
    {
        $policy = app(ConnectorScopePolicy::class);

        $this->assertSame(['plugins.read'], $policy->requiredFor('connector.operate', ['operation' => 'plugins.list']));
        $this->assertSame(['plugins.manage'], $policy->requiredFor('connector.operate', ['operation' => 'plugin.delete']));
        $this->assertSame(['themes.manage'], $policy->requiredFor('connector.operate', ['operation' => 'theme.activate']));
        $this->assertSame(['cache.manage'], $policy->requiredFor('connector.operate', ['operation' => 'cache.purge']));
        $this->assertSame(['cron.manage'], $policy->requiredFor('connector.operate', ['operation' => 'cron.run']));
        $this->assertSame(['backup.create'], $policy->requiredFor('connector.operate', ['operation' => 'backup.create']));
        $this->assertSame(['filesystem.read'], $policy->requiredFor('connector.operate', ['operation' => 'filesystem.inspect']));
        $this->assertSame(['database.manage'], $policy->requiredFor('connector.operate', ['operation' => 'database.optimize']));
    }

    public function test_sensitive_write_scopes_are_not_enabled_by_default(): void
    {
        foreach (['plugins.manage', 'themes.manage', 'cache.manage', 'cron.manage', 'backup.create', 'backup.restore', 'database.manage'] as $scope) {
            $this->assertContains($scope, PairingService::CAPABILITIES);
            $this->assertNotContains($scope, PairingService::SAFE_DEFAULT_SCOPES);
        }
    }

    public function test_unknown_advanced_operation_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown connector semantic operation.');

        app(ConnectorScopePolicy::class)->requiredFor('connector.operate', ['operation' => 'database.raw']);
    }
}
