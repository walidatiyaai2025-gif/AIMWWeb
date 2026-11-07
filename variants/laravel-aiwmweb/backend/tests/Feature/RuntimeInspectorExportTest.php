<?php

namespace Tests\Feature;

use App\Http\Controllers\RuntimeInspectorController;
use App\Models\Connector;
use App\Models\ContentItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\RuntimeHealthService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RuntimeInspectorExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspector_route_requires_authenticated_tenant_context_and_diagnostics_permission(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/wordpress/runtime-inspector/report', 'GET'));
        $this->assertSame(RuntimeInspectorController::class, $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->getJson('/tenants/wordpress/runtime-inspector/report')->assertUnauthorized();

        $reader = User::factory()->create();
        $this->membership($reader, 'wordpress', ['tenant.view', 'sites.view', 'content.view']);
        $this->actingAs($reader)
            ->getJson('/tenants/wordpress/runtime-inspector/report')
            ->assertForbidden();

        $inspector = User::factory()->create();
        $this->membership($inspector, 'other', ['tenant.view', 'diagnostics.view']);
        $this->actingAs($inspector)
            ->getJson('/tenants/wordpress/runtime-inspector/report')
            ->assertNotFound();
    }

    public function test_inspector_reports_genuine_connector_scope_and_content_counts_without_secrets(): void
    {
        $user = User::factory()->create(['email' => 'secret-canary@example.test']);
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'Private WordPress Secret Name',
            'url' => 'https://secret-wordpress.example.test/wp?token=SHOULD_NOT_LEAK',
            'connection_status' => 'paired',
            'status' => 'active',
        ]);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => '12345678-1234-4123-8123-123456789abc',
            'encrypted_secret' => 'CANARY_CONNECTOR_SECRET_NEVER_EXPORT',
            'verified_at' => now(),
            'capabilities' => ['health', 'content.read'],
            'enabled_scopes' => ['health', 'content.read'],
        ]);
        ContentItem::query()->create([
            'site_id' => $site->id,
            'type' => 'post',
            'remote_id' => 12,
            'title' => 'PRIVATE_ARTICLE_CANARY',
            'slug' => 'private-post',
            'body' => '<p>ARTICLE_BODY_CANARY</p>',
            'status' => 'publish',
        ]);
        SyncRun::query()->create([
            'site_id' => $site->id,
            'state' => 'completed',
            'resources' => ['posts', 'pages'],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        Http::fake();
        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('schema', RuntimeInspectorController::SCHEMA)
            ->assertJsonPath('safety.redacted', true)
            ->assertJsonPath('safety.read_only', true)
            ->assertJsonPath('wordpress_plugin.name', 'AIMW Connector')
            ->assertJsonPath('wordpress_plugin.bundle_version', '0.2.3')
            ->assertJsonPath('wordpress_plugin.install_target', 'wordpress_plugin')
            ->assertJsonPath('sites.0.connector.installed_version', 'unknown')
            ->assertJsonPath('sites.0.site_id', $site->id)
            ->assertJsonPath('sites.0.connector.enabled_scopes.1', 'content.read')
            ->assertJsonPath('sites.0.record_counts.posts', 1)
            ->assertJsonPath('sites.0.record_counts.pages', 0)
            ->assertJsonPath('sites.0.last_sync.state', 'completed')
            ->assertJsonPath('sites.0.finding', 'CANONICAL_ROWS_PRESENT');

        foreach ([
            'CANARY_CONNECTOR_SECRET_NEVER_EXPORT',
            '12345678-1234-4123-8123-123456789abc',
            'secret-wordpress.example.test',
            'SHOULD_NOT_LEAK',
            'PRIVATE_ARTICLE_CANARY',
            'ARTICLE_BODY_CANARY',
            'secret-canary@example.test',
            'encrypted_secret',
            'stack_trace',
            'APP_KEY',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent(), 'Inspector must redact '.$secret);
        }

        Http::assertNothingSent();
    }

    public function test_missing_connector_and_disabled_read_scope_are_reported_without_fake_success(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $missing = Site::query()->create(['name' => 'Not paired', 'url' => 'https://missing.test']);
        $disabled = Site::query()->create(['name' => 'Disabled read', 'url' => 'https://disabled.test', 'connection_status' => 'paired']);
        Connector::query()->create([
            'site_id' => $disabled->id,
            'identity' => '12345678-1234-4123-8123-eeeeeeeeeeee',
            'encrypted_secret' => 'protected',
            'verified_at' => now(),
            'enabled_scopes' => ['health'],
            'capabilities' => ['health', 'content.read'],
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report')
            ->assertOk()
            ->assertJsonPath('sites.0.site_id', $missing->id)
            ->assertJsonPath('sites.0.finding', 'CONNECTOR_NOT_PAIRED')
            ->assertJsonPath('sites.1.site_id', $disabled->id)
            ->assertJsonPath('sites.1.finding', 'CONTENT_READ_SCOPE_DISABLED');
    }

    public function test_unverified_pairing_without_negotiated_capabilities_reports_verification_blocker_first(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => 'Pending pairing verification',
            'url' => 'https://unverified-no-capabilities.example.test',
            'connection_status' => 'paired',
            'status' => 'active',
        ]);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => '12345678-1234-4123-8123-999999999999',
            'encrypted_secret' => 'PENDING_PAIRING_SECRET_CANARY',
            'verified_at' => null,
            'capabilities' => [],
            'enabled_scopes' => [],
        ]);
        app(TenantContext::class)->forget();

        Http::fake();
        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report?probe=1')
            ->assertOk()
            ->assertJsonPath('sites.0.site_id', $site->id)
            ->assertJsonPath('sites.0.finding', 'CONNECTOR_NOT_VERIFIED')
            ->assertJsonPath('sites.0.connector.verified', false)
            ->assertJsonPath('sites.0.connector.enabled_scopes', [])
            ->assertJsonPath('sites.0.remote_probe.attempted', false);

        $this->assertStringNotContainsString('PENDING_PAIRING_SECRET_CANARY', $response->getContent());
        $this->assertStringNotContainsString('unverified-no-capabilities.example.test', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_inspector_reports_unverified_and_unsupported_connector_even_with_existing_content(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);

        $unverified = Site::query()->create([
            'name' => 'Unverified WordPress site',
            'url' => 'https://unverified.example.test',
            'connection_status' => 'paired',
            'status' => 'active',
        ]);
        Connector::query()->create([
            'site_id' => $unverified->id,
            'identity' => '12345678-1234-4123-8123-aabbccddee01',
            'encrypted_secret' => 'UNVERIFIED_SECRET_CANARY',
            'verified_at' => null,
            'enabled_scopes' => ['health', 'content.read'],
            'capabilities' => ['health', 'content.read'],
        ]);
        ContentItem::query()->create([
            'site_id' => $unverified->id,
            'type' => 'post',
            'remote_id' => 17,
            'title' => 'UNVERIFIED_CONTENT_CANARY',
            'slug' => 'unverified-content',
            'status' => 'publish',
        ]);
        SyncRun::query()->create([
            'site_id' => $unverified->id,
            'state' => 'completed',
            'resources' => ['posts'],
            'completed_at' => now(),
        ]);

        $unsupported = Site::query()->create([
            'name' => 'Read capability not negotiated',
            'url' => 'https://unsupported.example.test',
            'connection_status' => 'verified',
            'status' => 'active',
        ]);
        Connector::query()->create([
            'site_id' => $unsupported->id,
            'identity' => '12345678-1234-4123-8123-aabbccddee02',
            'encrypted_secret' => 'UNSUPPORTED_SECRET_CANARY',
            'verified_at' => now(),
            'enabled_scopes' => ['health', 'content.read'],
            'capabilities' => ['health'],
        ]);
        app(TenantContext::class)->forget();

        Http::fake();
        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report?probe=1')
            ->assertOk()
            ->assertJsonPath('sites.0.site_id', $unverified->id)
            ->assertJsonPath('sites.0.finding', 'CONNECTOR_NOT_VERIFIED')
            ->assertJsonPath('sites.0.connector.verified', false)
            ->assertJsonPath('sites.0.record_counts.posts', 1)
            ->assertJsonPath('sites.0.last_sync.state', 'completed')
            ->assertJsonPath('sites.0.remote_probe.attempted', false)
            ->assertJsonPath('sites.1.site_id', $unsupported->id)
            ->assertJsonPath('sites.1.finding', 'CONTENT_READ_UNSUPPORTED')
            ->assertJsonPath('sites.1.connector.verified', true)
            ->assertJsonPath('sites.1.remote_probe.attempted', false);

        foreach (['UNVERIFIED_SECRET_CANARY', 'UNSUPPORTED_SECRET_CANARY', 'UNVERIFIED_CONTENT_CANARY'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_optional_live_probe_uses_one_signed_read_without_exposing_remote_payload(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'Sensitive remote',
            'url' => 'https://wp.test',
            'status' => 'active',
            'connection_status' => 'paired',
        ]);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => '12345678-1234-4123-8123-abcd12345678',
            'encrypted_secret' => 'SECRET_REMOTE_CANARY',
            'verified_at' => now(),
            'enabled_scopes' => ['health', 'content.read'],
            'capabilities' => ['health', 'content.read'],
        ]);
        app(TenantContext::class)->forget();

        Http::fake([
            'https://wp.test/wp-json/aimw/v1/capabilities' => Http::response([
                'state' => 'CONNECTED',
                'plugin_version' => '0.2.3',
                'secret' => 'REMOTE_RESPONSE_CANARY',
                'runtime' => ['states' => ['content.read' => ['state' => 'SUPPORTED_ENABLED']]],
            ]),
        ]);
        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report?probe=1')
            ->assertOk()
            ->assertJsonPath('sites.0.remote_probe.attempted', true)
            ->assertJsonPath('sites.0.connector.installed_version', '0.2.3');

        $this->assertStringNotContainsString('REMOTE_RESPONSE_CANARY', $response->getContent());
        $this->assertStringNotContainsString('SECRET_REMOTE_CANARY', $response->getContent());
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/aimw/v1/capabilities'));
        Http::assertSentCount(1);
    }

    public function test_explicit_probe_skips_three_ineligible_sites_and_reaches_later_verified_connector(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);

        // The first three rows are genuinely ineligible and must not exhaust the probe budget.
        Site::query()->create([
            'name' => 'Unpaired first', 'url' => 'https://unpaired-first.example.test',
            'connection_status' => 'paired',
        ]);
        $unverified = Site::query()->create([
            'name' => 'Unverified second', 'url' => 'https://unverified-second.example.test',
            'connection_status' => 'paired',
        ]);
        Connector::query()->create([
            'site_id' => $unverified->id,
            'identity' => '12345678-1234-4123-8123-000000000002',
            'encrypted_secret' => 'UNVERIFIED_SECRET_CANARY',
            'verified_at' => null,
            'capabilities' => ['health', 'content.read'],
            'enabled_scopes' => ['health', 'content.read'],
        ]);
        $revoked = Site::query()->create([
            'name' => 'Revoked third', 'url' => 'https://revoked-third.example.test',
            'connection_status' => 'paired',
        ]);
        Connector::query()->create([
            'site_id' => $revoked->id,
            'identity' => '12345678-1234-4123-8123-000000000003',
            'encrypted_secret' => 'REVOKED_SECRET_CANARY',
            'verified_at' => now(),
            'revoked_at' => now(),
            'capabilities' => ['health', 'content.read'],
            'enabled_scopes' => ['health', 'content.read'],
        ]);
        $eligible = Site::query()->create([
            'name' => 'Eligible fourth', 'url' => 'https://eligible-fourth.example.test',
            'connection_status' => 'paired',
        ]);
        Connector::query()->create([
            'site_id' => $eligible->id,
            'identity' => '12345678-1234-4123-8123-000000000004',
            'encrypted_secret' => 'ELIGIBLE_SECRET_CANARY',
            'verified_at' => now(),
            'capabilities' => ['health', 'content.read'],
            'enabled_scopes' => ['health', 'content.read'],
        ]);
        app(TenantContext::class)->forget();

        Http::fake([
            'https://eligible-fourth.example.test/wp-json/aimw/v1/capabilities' => Http::response([
                'state' => 'CONNECTED',
                'plugin_version' => '0.2.3',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report?probe=1')
            ->assertOk()
            ->assertJsonPath('sites.0.remote_probe.state', 'not_run')
            ->assertJsonPath('sites.1.remote_probe.state', 'not_run')
            ->assertJsonPath('sites.2.remote_probe.state', 'not_run')
            ->assertJsonPath('sites.3.site_id', $eligible->id)
            ->assertJsonPath('sites.3.remote_probe.attempted', true)
            ->assertJsonPath('sites.3.connector.installed_version', '0.2.3');
        $this->assertStringNotContainsString('ELIGIBLE_SECRET_CANARY', $response->getContent());
        $this->assertStringNotContainsString('UNVERIFIED_SECRET_CANARY', $response->getContent());
        $this->assertStringNotContainsString('REVOKED_SECRET_CANARY', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'eligible-fourth.example.test/wp-json/aimw/v1/capabilities'));
    }

    public function test_explicit_probe_limits_to_three_eligible_connectors(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        app(TenantContext::class)->activate($membership->tenant, $membership);

        foreach (range(1, 4) as $index) {
            $site = Site::query()->create([
                'name' => 'Eligible '.$index,
                'url' => 'https://eligible-'.$index.'.example.test',
                'connection_status' => 'paired',
            ]);
            Connector::query()->create([
                'site_id' => $site->id,
                'identity' => sprintf('12345678-1234-4123-8123-%012d', $index),
                'encrypted_secret' => 'PRIVATE_CONNECTOR_CANARY',
                'verified_at' => now(),
                'capabilities' => ['health', 'content.read'],
                'enabled_scopes' => ['health', 'content.read'],
            ]);
        }
        app(TenantContext::class)->forget();

        Http::fake([
            'https://eligible-*.example.test/wp-json/aimw/v1/capabilities' => Http::response([
                'state' => 'CONNECTED', 'plugin_version' => '0.2.3',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report?probe=1')
            ->assertOk();
        foreach (range(0, 2) as $index) {
            $response->assertJsonPath('sites.'.$index.'.remote_probe.attempted', true);
            $response->assertJsonPath('sites.'.$index.'.connector.installed_version', '0.2.3');
        }
        $response->assertJsonPath('sites.3.remote_probe.attempted', false)
            ->assertJsonPath('sites.3.remote_probe.state', 'limited')
            ->assertJsonPath('sites.3.connector.installed_version', 'unknown');
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'eligible-4.example.test'));
    }

    public function test_bundle_version_matches_canonical_wordpress_plugin_header_and_constant(): void
    {
        $plugin = file_get_contents(base_path('../connector/aimw-connector/aimw-connector.php'));
        $this->assertIsString($plugin);
        $version = (string) config('aimw_connector.plugin_version');
        $this->assertMatchesRegularExpression('/^ \\* Version: '.preg_quote($version, '/').'$/m', $plugin);
        $this->assertStringContainsString("public const PLUGIN_VERSION = '".$version."';", $plugin);
    }

    public function test_read_only_inspector_exports_fresh_cpanel_cron_and_worker_cycle_evidence(): void
    {
        config([
            'cpanel.enabled' => true,
            'app.url' => 'https://aiwm.test',
            'cpanel.cron_enabled' => true,
            'cpanel.preflight_passed' => true,
            'queue.default' => 'database',
            'cache.default' => 'database',
        ]);
        // cPanel correctly requires HTTPS; exercise the secured host transport.
        $this->withServerVariables(['HTTPS' => 'on']);

        $user = User::factory()->create();
        $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        $store = Cache::store('database');
        $store->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 10, now()->addMinutes(10));
        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 12, now()->addMinutes(10));
        $originalScheduler = $store->get(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY);
        $originalWorker = $store->get(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY);

        Http::fake();
        $response = $this->actingAs($user)
            ->getJson('https://aiwm.test/tenants/wordpress/runtime-inspector/report')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('safety.read_only', true)
            ->assertJsonPath('runtime_health.profile', 'cpanel')
            ->assertJsonPath('runtime_health.hosting_preflight', 'operator_validated')
            ->assertJsonPath('runtime_health.runtime_modes.queue', 'database')
            ->assertJsonPath('runtime_health.runtime_modes.cache', 'database')
            ->assertJsonPath('runtime_health.runtime_modes.cron', 'expected')
            ->assertJsonPath('runtime_health.scheduler_heartbeat.status', 'ok')
            ->assertJsonPath('runtime_health.scheduler_heartbeat.evidence', 'scheduler_heartbeat_observed')
            ->assertJsonPath('runtime_health.worker_cycle.status', 'ok')
            ->assertJsonPath('runtime_health.worker_cycle.evidence', 'bounded_worker_cycle_completed');

        $this->assertGreaterThanOrEqual(0, $response->json('runtime_health.worker_cycle.age_seconds'));
        $this->assertLessThanOrEqual(180, $response->json('runtime_health.worker_cycle.age_seconds'));
        $this->assertSame($originalScheduler, $store->get(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY));
        $this->assertSame($originalWorker, $store->get(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY));
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_inspector_fails_closed_for_missing_stale_future_and_disabled_cpanel_receipts(): void
    {
        config([
            'cpanel.enabled' => true,
            'app.url' => 'https://aiwm.test',
            'cpanel.cron_enabled' => true,
            'cpanel.preflight_passed' => true,
            'queue.default' => 'database',
            'cache.default' => 'database',
        ]);
        // cPanel correctly requires HTTPS; exercise the secured host transport.
        $this->withServerVariables(['HTTPS' => 'on']);
        $user = User::factory()->create();
        $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        $url = 'https://aiwm.test/tenants/wordpress/runtime-inspector/report';
        $store = Cache::store('database');
        $store->forget(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY);
        $store->forget(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY);

        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('runtime_health.scheduler_heartbeat.reason_code', 'receipt_missing')
            ->assertJsonPath('runtime_health.worker_cycle.status', 'unavailable')
            ->assertJsonPath('runtime_health.worker_cycle.reason_code', 'receipt_missing');

        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() - 300, now()->addMinutes(10));
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('runtime_health.worker_cycle.status', 'unavailable')
            ->assertJsonPath('runtime_health.worker_cycle.reason_code', 'receipt_stale');

        $store->put(RuntimeHealthService::CPANEL_QUEUE_TICK_RECEIPT_KEY, time() + 60, now()->addMinutes(10));
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('runtime_health.worker_cycle.status', 'unavailable')
            ->assertJsonPath('runtime_health.worker_cycle.reason_code', 'receipt_invalid');

        config(['cpanel.cron_enabled' => false]);
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('runtime_health.runtime_modes.cron', 'disabled')
            ->assertJsonPath('runtime_health.scheduler_heartbeat.status', 'disabled')
            ->assertJsonPath('runtime_health.worker_cycle.reason_code', 'cron_disabled');

        config(['cpanel.cron_enabled' => true, 'cpanel.preflight_passed' => false]);
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('runtime_health.hosting_preflight', 'pending')
            ->assertJsonPath('runtime_health.worker_cycle.status', 'unavailable')
            ->assertJsonPath('runtime_health.worker_cycle.reason_code', 'preflight_pending');
    }

    public function test_vps_inspector_does_not_invent_a_worker_receipt_or_export_unknown_driver_names(): void
    {
        config([
            'cpanel.enabled' => false,
            'queue.default' => 'LEAK_PRIVATE_QUEUE_URL',
            'cache.default' => 'array',
        ]);
        $user = User::factory()->create();
        $this->membership($user, 'wordpress', ['tenant.view', 'diagnostics.view']);
        Cache::put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time() - 5, now()->addMinutes(10));

        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report')
            ->assertOk()
            ->assertJsonPath('runtime_health.profile', 'vps')
            ->assertJsonPath('runtime_health.hosting_preflight', 'not_applicable')
            ->assertJsonPath('runtime_health.runtime_modes.queue', 'other')
            ->assertJsonPath('runtime_health.runtime_modes.cron', 'not_applicable')
            ->assertJsonPath('runtime_health.scheduler_heartbeat.status', 'ok')
            ->assertJsonPath('runtime_health.worker_cycle.status', 'not_advertised')
            ->assertJsonPath('runtime_health.worker_cycle.reason_code', 'no_cpanel_worker_receipt');
        $this->assertStringNotContainsString('LEAK_PRIVATE_QUEUE_URL', $response->getContent());
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'runtime-inspector-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }
}
