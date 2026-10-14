<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomeWorkspacePreviewAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-D4607F943C';

    public function test_source_and_laravel_public_welcome_bind_the_exact_workspace_preview_anchor(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('href="#workspace-preview"', $source);
        $this->assertStringContainsString('id="workspace-preview"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('href="#workspace-preview"', $view);
        $this->assertStringContainsString('id="workspace-preview"', $view);
        $this->assertStringContainsString(
            'data-workspace-preview-anchor-target="AIMW-CONT-D4607F943C"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="workspace-preview"'));
    }

    public function test_public_welcome_renders_workspace_preview_navigation_in_english_and_arabic(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#workspace-preview"', false)
            ->assertSee('id="workspace-preview"', false)
            ->assertSee('Explore the workspace')
            ->assertSee('Workspace preview');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#workspace-preview"', false)
            ->assertSee('id="workspace-preview"', false)
            ->assertSee('شاهد مساحة العمل')
            ->assertSee('معاينة مساحة العمل');
    }

    public function test_public_welcome_keeps_the_existing_tenant_neutral_404_boundary(): void
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
            '404 tenant boundary: the public Welcome screen must not expose a tenant-scoped alias.',
        );

        $this->post('/welcome')->assertStatus(405);
    }
}
