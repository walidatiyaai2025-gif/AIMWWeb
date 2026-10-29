<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomePricingAnchorTerminalityTest extends TestCase
{
    // Exact-head CI retrigger after official parity materialization 4912e1b.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-3C205B1901';

    public function test_source_and_laravel_public_welcome_bind_the_exact_pricing_anchor(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('@page "/welcome"', $source);
        $this->assertStringContainsString('href="#pricing"', $source);
        $this->assertStringContainsString('id="pricing"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('href="#pricing"', $view);
        $this->assertStringContainsString('id="pricing"', $view);
        $this->assertStringContainsString(
            'data-pricing-anchor-target="'.self::OPERATION_ID.'"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="pricing"'));
    }

    public function test_public_welcome_renders_pricing_navigation_and_real_pricing_target_in_both_locales(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#pricing"', false)
            ->assertSee('data-pricing-anchor-target="'.self::OPERATION_ID.'"', false)
            ->assertSee('>Pricing</a>', false)
            ->assertSee('Start free. Expand when needed.');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#pricing"', false)
            ->assertSee('data-pricing-anchor-target="'.self::OPERATION_ID.'"', false)
            ->assertSee('>الخطط</a>', false)
            ->assertSee('ابدأ مجانًا ووسّع عند الحاجة.');
    }

    public function test_public_welcome_pricing_anchor_preserves_tenant_neutral_read_only_boundary(): void
    {
        $route = Route::getRoutes()->match(Request::create('/welcome', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class, $route->getActionName());
        $this->assertSame('public.welcome', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());

        $tenantWelcomeAliases = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($candidate): string => $candidate->uri())
            ->filter(static fn (string $uri): bool => str_contains($uri, 'welcome') && str_contains($uri, '{tenant}'))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $tenantWelcomeAliases,
            '404 tenant boundary: the public Welcome pricing anchor must not expose a tenant-scoped alias.',
        );
        $this->get('/tenants/foreign/welcome')->assertRedirect('/login');
        $this->post('/welcome')->assertStatus(405);
    }

    public function test_pricing_control_keeps_its_marker_and_target_operation_specific(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'data-pricing-anchor-target="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="pricing"'));
    }
}
