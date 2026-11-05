<?php

namespace Tests\Feature;

use App\Connector\PairingService;
use App\Models\Connector;
use App\Models\ConnectorPairing;
use App\Models\Site;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WordPressConnectorStatelessPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_pairing_endpoint_is_stateless_but_token_issuance_stays_browser_protected(): void
    {
        $external = Route::getRoutes()->match(Request::create('/api/connector/pair', 'POST'));
        $this->assertContains('api', $external->gatherMiddleware());
        $this->assertContains('throttle:20,1', $external->gatherMiddleware());
        $this->assertNotContains('web', $external->gatherMiddleware());
        $this->assertNotContains('auth', $external->gatherMiddleware());

        $issuer = Route::getRoutes()->match(Request::create('/api/tenants/wordpress/sites/1/pairing', 'POST'));
        $this->assertContains('web', $issuer->gatherMiddleware());
        $this->assertContains('auth', $issuer->gatherMiddleware());
        $this->assertContains('tenant.context', $issuer->gatherMiddleware());
    }

    public function test_wordpress_can_complete_pairing_without_browser_session_or_csrf_token(): void
    {
        [$tenant, $site, $token] = $this->issueToken();
        $identity = (string) Str::uuid();

        $response = $this->postJson('/api/connector/pair', $this->payload($token, $identity))
            ->assertCreated()
            ->assertJsonPath('tenant_id', $tenant->id)
            ->assertJsonPath('site_id', $site->id)
            ->assertJsonPath('connector_id', $identity)
            ->assertJsonPath('protocol_version', '1');

        $this->assertSame(64, strlen((string) $response->json('secret')));
        $this->assertDatabaseHas('connectors', ['site_id' => $site->id, 'identity' => $identity]);
        $this->assertNotNull(ConnectorPairing::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))->sole()->used_at);
        $this->assertSame('paired', Site::withoutGlobalScopes()->findOrFail($site->id)->connection_status);
    }

    public function test_replayed_or_expired_token_cannot_bind_another_wordpress_connector(): void
    {
        [, $site, $token] = $this->issueToken();
        $initial = (string) Str::uuid();

        $this->postJson('/api/connector/pair', $this->payload($token, $initial))->assertCreated();
        $this->postJson('/api/connector/pair', $this->payload($token, (string) Str::uuid()))->assertStatus(410);
        $this->assertSame($initial, Connector::withoutGlobalScopes()
            ->where('site_id', $site->id)->sole()->identity);

        [, , $expiringToken] = $this->issueToken('another');
        ConnectorPairing::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $expiringToken))
            ->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/connector/pair', $this->payload($expiringToken, (string) Str::uuid()))
            ->assertStatus(410);
        $this->assertSame(1, Connector::withoutGlobalScopes()->count());
    }

    public function test_invalid_token_and_invalid_protocol_are_rejected_without_creating_connector(): void
    {
        [, , $token] = $this->issueToken();
        $this->postJson('/api/connector/pair', $this->payload(str_repeat('Z', 64), (string) Str::uuid()))
            ->assertNotFound();
        $this->postJson('/api/connector/pair', array_replace(
            $this->payload($token, (string) Str::uuid()), ['protocol_version' => '999'],
        ))->assertUnprocessable();
        $this->assertSame(0, Connector::withoutGlobalScopes()->count());
    }

    /** @return array{Tenant, Site, string} */
    private function issueToken(string $slug = 'wordpress'): array
    {
        $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        try {
            $site = Site::query()->create(['name' => 'NOB', 'url' => 'https://notonlybook.com']);
            $token = app(PairingService::class)->create($site);

            return [$tenant, $site, $token];
        } finally {
            $context->forget();
        }
    }

    /** @return array{token: string, identity: string, protocol_version: string, capabilities: list<string>} */
    private function payload(string $token, string $identity): array
    {
        return [
            'token' => $token,
            'identity' => $identity,
            'protocol_version' => '1',
            'capabilities' => ['health', 'content.read', 'connector.manage'],
        ];
    }
}
