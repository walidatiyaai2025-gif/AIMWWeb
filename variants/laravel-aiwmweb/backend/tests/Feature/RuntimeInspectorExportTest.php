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
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
                'secret' => 'REMOTE_RESPONSE_CANARY',
                'runtime' => ['states' => ['content.read' => ['state' => 'SUPPORTED_ENABLED']]],
            ]),
        ]);
        $response = $this->actingAs($user)
            ->getJson('/tenants/wordpress/runtime-inspector/report?probe=1')
            ->assertOk()
            ->assertJsonPath('sites.0.remote_probe.attempted', true);

        $this->assertStringNotContainsString('REMOTE_RESPONSE_CANARY', $response->getContent());
        $this->assertStringNotContainsString('SECRET_REMOTE_CANARY', $response->getContent());
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/aimw/v1/capabilities'));
        Http::assertSentCount(1);
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
