<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomePrimaryCtaActionTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-1456BE001F';

    public function test_source_and_laravel_bind_the_workspace_preview_primary_cta_action(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString(
            "onclick=\"window.location.href='@PrimaryCtaHref'; return false;\"",
            $source,
        );
        $this->assertStringContainsString(
            'private string PrimaryCtaHref => _isAuthenticated ? "/" : "/register";',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString(
            'href="{{ $isAuthenticated ? \'/\' : route(\'register\', [], false) }}"',
            $view,
        );
        $this->assertStringContainsString(
            'aria-label="{{ $welcomeLocale === \'ar\' ? \'فتح مساحة العمل\' : \'Open workspace\' }}"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
    }

    public function test_guest_and_authenticated_destinations_follow_the_authoritative_primary_cta_state(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/register"', false)
            ->assertSee('aria-label="Open workspace"', false)
            ->assertSee('+ Action');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/register"', false)
            ->assertSee('فتح مساحة العمل')
            ->assertSee('+ إجراء');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/"', false)
            ->assertSee('+ Action');
    }

    public function test_action_preserves_the_existing_public_tenant_neutral_read_only_boundary(): void
    {
        $route = Route::getRoutes()->match(Request::create('/welcome', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class, $route->getActionName());
        $this->assertSame('public.welcome', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());

        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));
        $this->assertStringNotContainsString('<form', $view);
        $this->post('/welcome')->assertStatus(405);
    }
}
