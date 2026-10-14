<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomeProductAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B1E9E1D6B0';

    public function test_source_and_laravel_public_welcome_bind_the_exact_product_anchor(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('href="#product"', $source);
        $this->assertStringContainsString('id="product"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('href="#product"', $view);
        $this->assertStringContainsString('id="product"', $view);
        $this->assertStringContainsString(
            'data-product-anchor-target="AIMW-CONT-B1E9E1D6B0"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="product"'));
    }

    public function test_public_welcome_renders_product_navigation_in_english_and_arabic(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#product"', false)
            ->assertSee('id="product"', false)
            ->assertSee('>Product</a>', false)
            ->assertSee('Operate WordPress as one system.');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#product"', false)
            ->assertSee('id="product"', false)
            ->assertSee('>المنتج</a>', false)
            ->assertSee('أدر ووردبريس كنظام واحد.');
    }

    public function test_public_welcome_keeps_tenant_neutral_404_boundary_for_product_anchor(): void
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
            '404 tenant boundary: the public Welcome product anchor must not expose a tenant-scoped alias.',
        );

        $this->post('/welcome')->assertStatus(405);
    }

    public function test_product_control_keeps_its_marker_and_target_operation_specific(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'data-product-anchor-target="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="product"'));
    }
}
