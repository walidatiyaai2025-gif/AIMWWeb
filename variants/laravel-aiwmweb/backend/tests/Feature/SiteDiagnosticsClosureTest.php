<?php

namespace Tests\Feature;

use App\Connector\AdvancedWordPressGateway;
use App\Connector\PairingService;
use App\Models\Connector;
use App\Models\Site;
use App\Models\SiteDiagnostic;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteConnectionState;
use App\Sites\SiteDiagnosticsService;
use App\Sites\SiteEntitlementHook;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SiteDiagnosticsClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_and_diagnostic_ids_do_not_cross_tenants(): void
    {
        [$tenantA, $memberA] = $this->tenant('site-a');
        [$tenantB, $memberB] = $this->tenant('site-b');
        $context = app(TenantContext::class);
        $context->activate($tenantB, $memberB);
        $site = Site::query()->create(['name' => 'B', 'url' => 'https://b.test']);
        $diagnostic = SiteDiagnostic::query()->create([
            'site_id' => $site->id,
            'classification' => SiteConnectionState::DISCONNECTED,
            'connection_state' => SiteConnectionState::DISCONNECTED,
            'checked_at' => now(),
        ]);
        SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'operation' => 'diagnostic_recheck',
            'status' => 'failed',
            'message' => 'not connected',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $context->activate($tenantA, $memberA);
        $this->assertNull(Site::query()->find($site->id));
        $this->assertNull(SiteDiagnostic::query()->find($diagnostic->id));
        $this->assertSame(0, SiteOperationHistory::query()->count());
    }

    public function test_disconnect_and_reconnect_reuse_canonical_connector_pairing(): void
    {
        [$tenant, $member] = $this->tenant('reconnect');
        app(TenantContext::class)->activate($tenant, $member);
        $site = Site::query()->create(['name' => 'WP', 'url' => 'https://wp.test']);
        $connector = $this->connector($site, ['health', 'connector.manage', 'diagnostics.read']);
        $gateway = new SiteGatewayStub;
        $service = $this->service($gateway);

        $disconnected = $service->disconnect($site);
        $this->assertSame(SiteConnectionState::DISCONNECTED, $disconnected['state']);
        $this->assertNotNull($connector->fresh()->revoked_at);
        $this->assertSame([], $connector->fresh()->enabled_scopes);
        $this->assertSame(1, $gateway->disconnectCount);

        $reconnect = $service->reconnect($site->fresh());
        $this->assertSame(64, strlen($reconnect['pairing_token']));
        $this->assertSame(1, \App\Models\ConnectorPairing::query()->where('site_id', $site->id)->count());
    }

    public function test_failed_rest_and_unhealthy_database_are_never_reported_green(): void
    {
        [$tenant, $member] = $this->tenant('degraded');
        app(TenantContext::class)->activate($tenant, $member);
        $site = Site::query()->create(['name' => 'WP', 'url' => 'https://wp.test']);
        $this->connector($site, ['health', 'diagnostics.read']);
        Http::fake(['https://wp.test/wp-json/' => Http::response(['message' => 'down'], 503)]);
        $gateway = new SiteGatewayStub;
        $gateway->healthPayload['database']['connected'] = false;

        $diagnostic = $this->service($gateway)->recheck($site);
        $this->assertSame(SiteConnectionState::DEGRADED, $diagnostic->classification);
        $this->assertSame(SiteConnectionState::TEMPORARILY_UNAVAILABLE, $diagnostic->rest_state);
        $this->assertSame(SiteConnectionState::DEGRADED, $diagnostic->database_state);
        $this->assertNotSame('healthy', strtolower($site->fresh()->health_state));
    }

    public function test_connector_auth_failure_disabled_scope_and_unsupported_protocol_are_distinct(): void
    {
        [$tenant, $member] = $this->tenant('states');
        app(TenantContext::class)->activate($tenant, $member);
        Http::fake(['*' => Http::response(['namespaces' => []], 200)]);

        $siteA = Site::query()->create(['name' => 'Auth', 'url' => 'https://auth.test']);
        $this->connector($siteA, ['health', 'diagnostics.read']);
        $authGateway = new SiteGatewayStub;
        $authGateway->capabilityException = new RuntimeException('Invalid connector signature.');
        $this->assertSame(SiteConnectionState::AUTH_FAILED, $this->service($authGateway)->recheck($siteA)->classification);

        $siteB = Site::query()->create(['name' => 'Disabled', 'url' => 'https://disabled.test']);
        $this->connector($siteB, ['health']);
        $this->assertSame(SiteConnectionState::CAPABILITY_DISABLED, $this->service(new SiteGatewayStub)->recheck($siteB)->classification);

        $siteC = Site::query()->create(['name' => 'Unsupported', 'url' => 'https://unsupported.test']);
        $this->connector($siteC, ['health', 'diagnostics.read']);
        $unsupported = new SiteGatewayStub;
        $unsupported->capabilityPayload['connection']['protocol_state'] = 'UNSUPPORTED';
        $this->assertSame(SiteConnectionState::UNSUPPORTED, $this->service($unsupported)->recheck($siteC)->classification);
    }

    public function test_capability_explorer_reports_owner_disabled_and_redacts_secrets(): void
    {
        [$tenant, $member] = $this->tenant('caps');
        app(TenantContext::class)->activate($tenant, $member);
        $site = Site::query()->create(['name' => 'Caps', 'url' => 'https://caps.test']);
        $this->connector($site, ['health', 'diagnostics.read'], ['health', 'diagnostics.read', 'cache.manage']);
        $gateway = new SiteGatewayStub;
        $gateway->capabilityPayload['runtime']['states']['cache.manage'] = ['state' => 'SUPPORTED_DISABLED', 'enabled' => false];
        $gateway->capabilityPayload['runtime']['secret'] = 'must-not-leak';

        $result = $this->service($gateway)->capabilities($site);
        $this->assertSame(SiteConnectionState::CONNECTED, $result['state']);
        $this->assertContains('cache.manage', $result['disabled_by_owner']);

        Http::fake(['*' => Http::response(['namespaces' => []], 200)]);
        $diagnostic = $this->service($gateway)->recheck($site);
        $this->assertSame('[REDACTED]', data_get($diagnostic->capability_summary, 'runtime.secret'));
        $this->assertStringNotContainsString('must-not-leak', json_encode($diagnostic->toArray(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('encrypted_secret', Connector::query()->firstOrFail()->toArray());
    }

    public function test_site_operation_history_supports_inventory_summary_preview_cleanup_and_clear(): void
    {
        [$tenant, $member] = $this->tenant('history');
        app(TenantContext::class)->activate($tenant, $member);
        $site = Site::query()->create(['name' => 'History', 'url' => 'https://history.test']);
        $history = new SiteOperationHistoryService;
        $record = $history->record($site->id, 'sync', true, 'done', ['token' => 'secret-value'], 3, 'b9c86f7f-a8a0-4530-a931-bcc336a531ab', now()->subDays(40));
        $this->assertSame('[REDACTED]', $record->details['token']);
        $this->assertCount(1, $history->get($site->id));
        $this->assertCount(1, $history->getAll());
        $this->assertSame($record->id, $history->getById($record->id)?->id);
        $this->assertSame(1, $history->getSummary()['successful']);
        $this->assertSame(1, $history->getStorageInfo()['record_count']);
        $this->assertSame(1, $history->previewCleanup(30, 0)['removable_count']);
        $this->assertSame(1, $history->cleanup(30, 0)['removed_count']);
        $history->record($site->id, 'refresh', false, 'failed');
        $this->assertSame(1, $history->clear($site->id));
    }

    public function test_entitlement_hook_is_truthful_when_pr_266_is_not_integrated(): void
    {
        [$tenant, $member] = $this->tenant('entitlement');
        app(TenantContext::class)->activate($tenant, $member);
        $snapshot = (new SiteEntitlementHook)->snapshot();

        $this->assertContains($snapshot['state'], [SiteConnectionState::TEMPORARILY_UNAVAILABLE, 'SUPPORTED_ENABLED']);
        if ($snapshot['state'] === SiteConnectionState::TEMPORARILY_UNAVAILABLE) {
            $this->assertNull($snapshot['site_limit']);
        }
    }

    private function service(SiteGatewayStub $gateway): SiteDiagnosticsService
    {
        return new SiteDiagnosticsService($gateway, app(PairingService::class), new SiteOperationHistoryService);
    }

    private function connector(Site $site, array $enabledScopes, ?array $capabilities = null): Connector
    {
        return Connector::query()->create([
            'site_id' => $site->id,
            'identity' => fake()->uuid(),
            'encrypted_secret' => 'server-only-secret',
            'protocol_version' => '1',
            'capabilities' => $capabilities ?? $enabledScopes,
            'enabled_scopes' => $enabledScopes,
        ]);
    }

    private function tenant(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $membership->setRelation('user', $user);
        app(TenantContext::class)->forget();

        return [$tenant, $membership];
    }
}

final class SiteGatewayStub implements AdvancedWordPressGateway
{
    public array $capabilityPayload = [
        'connection' => ['connection' => 'CONNECTED', 'protocol_state' => 'SUPPORTED_ENABLED'],
        'runtime' => ['states' => [], 'adapters' => []],
    ];
    public array $healthPayload = [
        'wordpress_version' => '6.9',
        'php_version' => '8.3',
        'memory_limit' => '256M',
        'disk' => ['free_bytes' => 1000],
        'rest' => ['state' => 'SUPPORTED_ENABLED'],
        'database' => ['connected' => true, 'table_count' => 12],
        'active_theme' => ['name' => 'Twenty Twenty-Six'],
        'plugins' => ['total' => 5, 'active' => 4],
        'cron' => ['events' => 3, 'due' => 0],
        'adapters' => [],
    ];
    public ?RuntimeException $capabilityException = null;
    public int $disconnectCount = 0;

    public function health(Site $site): array
    {
        return ['status' => 'healthy'];
    }

    public function capabilities(Site $site): array
    {
        if ($this->capabilityException) {
            throw $this->capabilityException;
        }

        return $this->capabilityPayload;
    }

    public function content(Site $site, ?string $modifiedAfter = null): array
    {
        return ['items' => []];
    }

    public function execute(Site $site, string $operationId, array $change): array
    {
        return ['status' => 'succeeded'];
    }

    public function read(Site $site, string $type, int $remoteId): array
    {
        return [];
    }

    public function rotateSecret(Site $site, string $newSecret): array
    {
        return ['rotated' => true];
    }

    public function disconnect(Site $site): array
    {
        $this->disconnectCount++;

        return ['disconnected' => true];
    }

    public function operate(Site $site, string $operationId, string $operation, array $arguments = []): array
    {
        return ['status' => 'succeeded', 'health' => $this->healthPayload];
    }
}
