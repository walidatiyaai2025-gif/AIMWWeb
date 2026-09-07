<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeBrandRouteVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-4C07560F0B';

    public function test_canonical_operation_is_the_pending_public_welcome_brand_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/welcome', $operation['route_screen']);
        $this->assertSame('AI WordPress Manager -> /welcome', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Welcome.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_public_welcome_route_is_web_only_and_renders_the_exact_brand_self_navigation(): void
    {
        $route = app('router')->getRoutes()->getByName('public.welcome');

        $this->assertNotNull($route);
        $this->assertSame('welcome', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);

        $this->get('/welcome')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/welcome"', false)
            ->assertSee('AI WordPress Manager');
    }

    public function test_public_control_is_identity_neutral_and_has_no_tenant_scoped_alias(): void
    {
        $anonymous = $this->get('/welcome')->assertOk()->getContent();

        $user = User::factory()->create([
            'name' => 'Tenant Alpha Sentinel',
            'email' => 'tenant-alpha-sentinel@example.test',
        ]);
        $authenticated = $this->actingAs($user)->get('/welcome')
            ->assertOk()
            ->assertDontSee('Tenant Alpha Sentinel')
            ->assertDontSee('tenant-alpha-sentinel@example.test')
            ->getContent();

        $this->assertSame($anonymous, $authenticated);

        // The canonical source is anonymous; a foreign/tenant-scoped alias must not exist.
        $this->get('/tenants/tenant-alpha-sentinel/welcome')->assertNotFound();
    }
}
