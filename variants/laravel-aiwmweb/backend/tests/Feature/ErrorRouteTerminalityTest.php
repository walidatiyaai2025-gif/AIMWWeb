<?php

namespace Tests\Feature;

use App\Http\Controllers\ErrorReadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErrorRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_canonical_error_route_identity_is_exact_and_read_only(): void
    {
        $ledgerPath = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($ledgerPath);

        $ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', 'AIMW-CONT-455F01DAC7');

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/Error', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Error.razor', $operation['current_source']);
        $this->assertFalse($operation['mutation']);
        $this->assertTrue($operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_reference_error_route_inherits_authenticated_fallback_policy(): void
    {
        $programPath = base_path('../../../src/AIWordPressManager.Web/Program.cs');
        $sourcePath = base_path('../../../src/AIWordPressManager.Web/Components/Pages/Error.razor');

        $this->assertFileExists($programPath);
        $this->assertFileExists($sourcePath);

        $program = file_get_contents($programPath);
        $source = file_get_contents($sourcePath);

        $this->assertStringContainsString('options.FallbackPolicy', $program);
        $this->assertStringContainsString('RequireAuthenticatedUser()', $program);
        $this->assertStringContainsString('@page "/Error"', $source);
        $this->assertStringNotContainsString('[AllowAnonymous]', $source);
    }

    public function test_error_route_is_explicit_authenticated_session_route_without_tenant_or_direct_id_surface(): void
    {
        $route = Route::getRoutes()->getByName('canonical.error');

        $this->assertNotNull($route);
        $this->assertSame(ErrorReadController::class, $route->getActionName());
        $this->assertSame('Error', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_guest_error_route_access_fails_closed_to_login(): void
    {
        $this->get('/Error')->assertRedirect('/login');
    }

    public function test_error_surface_renders_authoritative_tracking_ids_without_sensitive_details(): void
    {
        Carbon::setTestNow('2026-08-30 17:30:00');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withHeaders([
            'X-Request-ID' => 'error-route-request-0001',
            'X-Correlation-ID' => 'error-route-correlation-0001',
        ])->get('/Error?exception=database-password-secret&tenant=foreign-secret');

        $response
            ->assertOk()
            ->assertHeader('X-Request-ID', 'error-route-request-0001')
            ->assertHeader('X-Correlation-ID', 'error-route-correlation-0001')
            ->assertSee('data-canonical-operation="AIMW-CONT-455F01DAC7"', false)
            ->assertSee('An unexpected error occurred')
            ->assertSee('Tracking information')
            ->assertSee('error-route-request-0001')
            ->assertSee('error-route-correlation-0001')
            ->assertSee('2026-08-30 17:30:00')
            ->assertDontSee('database-password-secret')
            ->assertDontSee('foreign-secret')
            ->assertDontSee('Stack trace')
            ->assertDontSee('Exception message');
    }

    public function test_invalid_caller_tracking_headers_are_not_reflected_for_authenticated_session(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->withHeaders([
            'X-Request-ID' => '<script>alert(1)</script>',
            'X-Correlation-ID' => 'password=must-not-render',
        ])->get('/Error');

        $requestId = (string) $response->headers->get('X-Request-ID');
        $correlationId = (string) $response->headers->get('X-Correlation-ID');

        $response
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('must-not-render');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $requestId);
        $this->assertSame($requestId, $correlationId);
    }

    public function test_authenticated_error_surface_does_not_leak_identity_or_foreign_tenant_data(): void
    {
        Carbon::setTestNow('2026-08-30 17:31:00');
        $alpha = User::factory()->create(['name' => 'Alpha Tenant Sentinel', 'email' => 'alpha-error@example.test']);
        $beta = User::factory()->create(['name' => 'Beta Tenant Sentinel', 'email' => 'beta-error@example.test']);
        $headers = [
            'X-Request-ID' => 'error-route-request-0002',
            'X-Correlation-ID' => 'error-route-correlation-0002',
        ];

        $alphaHtml = $this->actingAs($alpha)->withHeaders($headers)->get('/Error')->assertOk()->getContent();
        $this->app['auth']->forgetGuards();
        $betaHtml = $this->actingAs($beta)->withHeaders($headers)->get('/Error')->assertOk()->getContent();

        $this->assertSame($alphaHtml, $betaHtml);
        foreach ([$alphaHtml, $betaHtml] as $html) {
            $this->assertStringNotContainsString('Alpha Tenant Sentinel', $html);
            $this->assertStringNotContainsString('Beta Tenant Sentinel', $html);
            $this->assertStringNotContainsString('alpha-error@example.test', $html);
            $this->assertStringNotContainsString('beta-error@example.test', $html);
            $this->assertStringNotContainsString('/tenants/', $html);
        }
    }
}
