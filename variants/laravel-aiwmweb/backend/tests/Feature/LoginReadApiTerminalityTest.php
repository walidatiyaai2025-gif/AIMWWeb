<?php

namespace Tests\Feature;

use App\Http\Controllers\LoginReadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginReadApiTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_row_is_the_terminal_low_risk_get_login_contract(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', 'AIMW-OPER-ABB41FC891');

        $this->assertNotNull($row);
        $this->assertSame('api', $row['kind']);
        $this->assertSame('operations', $row['domain']);
        $this->assertSame('/login', $row['route_screen']);
        $this->assertSame('HTTP GET /login', $row['visible_control']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('ADAPTED', $row['migration_state']);
    }

    public function test_login_get_is_anonymous_and_resolves_to_a_real_controller(): void
    {
        $route = Route::getRoutes()->match(Request::create('/login', 'GET'));

        $this->assertSame(LoginReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('login', $route->getName());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());

        $this->get('/login')->assertOk();
    }

    public function test_anonymous_login_renders_real_form_and_escapes_read_inputs(): void
    {
        $response = $this->get('/login?returnUrl='.urlencode('/tenants/alpha/workspace?tab=ops').'&error='.urlencode('<script>alert(1)</script>'));

        $response->assertOk()
            ->assertSee('AI WordPress Manager')
            ->assertSee('action="/api/login"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="returnUrl" value="/tenants/alpha/workspace?tab=ops"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_authenticated_login_redirects_only_to_safe_local_paths(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login?returnUrl='.urlencode('/tenants/alpha/workspace?tab=ops'))
            ->assertRedirect('/tenants/alpha/workspace?tab=ops');

        foreach (['https://evil.example/phish', '//evil.example/phish', '/%2F%2Fevil.example/phish', '/%5Cevil.example/phish'] as $unsafe) {
            $this->actingAs($user)
                ->get('/login?returnUrl='.urlencode($unsafe))
                ->assertRedirect('/');
        }
    }
}
