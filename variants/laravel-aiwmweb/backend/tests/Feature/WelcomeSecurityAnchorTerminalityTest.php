<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomeSecurityAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6458D3030B';

    public function test_source_and_laravel_public_welcome_bind_the_exact_security_anchor(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('@page "/welcome"', $source);
        $this->assertStringContainsString('href="#security"', $source);
        $this->assertStringContainsString('id="security"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('href="#security"', $view);
        $this->assertStringContainsString('id="security"', $view);
        $this->assertStringContainsString(
            'data-security-anchor-target="'.self::OPERATION_ID.'"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="security"'));
    }

    public function test_public_welcome_renders_security_navigation_and_source_aligned_security_content_in_both_locales(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#security"', false)
            ->assertSee('id="security"', false)
            ->assertSee('>Security</a>', false)
            ->assertSee('CONTROL & SECURITY')
            ->assertSee('Permission aware')
            ->assertSee('Protected secrets')
            ->assertSee('Actionable diagnostics')
            ->assertSee('Auditable execution');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#security"', false)
            ->assertSee('id="security"', false)
            ->assertSee('>الأمان</a>', false)
            ->assertSee('التحكم والأمان')
            ->assertSee('صلاحيات دقيقة')
            ->assertSee('أسرار معزولة')
            ->assertSee('تشخيص قابل للإرسال')
            ->assertSee('تنفيذ مدقق');
    }

    public function test_public_welcome_security_anchor_preserves_tenant_neutral_read_only_boundary(): void
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

        $this->assertSame([], $tenantWelcomeAliases);
        $this->post('/welcome')->assertStatus(405);
    }

    public function test_security_control_keeps_its_marker_and_target_operation_specific(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'data-security-anchor-target="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="security"'));
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-3C205B1901"', $view);
    }
}
