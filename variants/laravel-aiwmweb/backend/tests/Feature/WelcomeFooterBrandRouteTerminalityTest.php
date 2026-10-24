<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomeFooterBrandRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-C7CB5EE184';

    public function test_source_and_laravel_bind_the_exact_footer_brand_self_navigation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString(
            '<footer class="public-footer">',
            $source,
        );
        $this->assertStringContainsString(
            '<a class="public-brand" href="/welcome"><span class="brand-symbol small">AI</span><span><strong>AI WordPress Manager</strong><small>Operations OS</small></span></a>',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('class="footer-brand"', $view);
        $this->assertStringContainsString(
            'data-canonical-operation="'.self::OPERATION_ID.'"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
    }

    public function test_footer_brand_renders_as_a_real_self_link_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->get('/welcome?lang='.$locale)
                ->assertOk()
                ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
                ->assertSee('href="/welcome"', false)
                ->assertSee('AI WordPress Manager · Operations OS');
        }
    }

    public function test_footer_brand_preserves_public_read_only_tenant_neutral_boundary(): void
    {
        $route = Route::getRoutes()->match(Request::create('/welcome', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class, $route->getActionName());
        $this->assertSame('public.welcome', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());

        $user = User::factory()->create([
            'name' => 'Footer Brand Sentinel',
            'email' => 'footer-brand-sentinel@example.test',
        ]);

        $this->actingAs($user)
            ->get('/welcome')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('Footer Brand Sentinel')
            ->assertDontSee('footer-brand-sentinel@example.test');

        $this->get('/tenants/foreign/welcome')->assertNotFound();
        $this->post('/welcome')->assertStatus(405);
    }

    public function test_footer_brand_marker_is_operation_specific_and_does_not_claim_the_header_brand(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="AIMW-AI-4C07560F0B"'));
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="AIMW-CONT-247E791DA7"'));
    }
}
