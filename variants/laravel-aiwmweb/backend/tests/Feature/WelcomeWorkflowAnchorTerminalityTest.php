<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomeWorkflowAnchorTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-EE531AE1FC';

    public function test_source_and_real_public_welcome_view_bind_the_exact_workflow_anchor_operation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('@page "/welcome"', $source);
        $this->assertStringContainsString('href="#workflow"', $source);
        $this->assertStringContainsString('id="workflow"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('href="#workflow"', $view);
        $this->assertStringContainsString('id="workflow"', $view);
        $this->assertStringContainsString(
            'data-workflow-anchor-target="AIMW-CONT-EE531AE1FC"',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertSame(1, substr_count($view, 'id="workflow"'));
    }

    public function test_public_welcome_has_404_tenant_alias_boundary_without_tenant_selector(): void
    {
        $route = Route::getRoutes()->match(Request::create('/welcome', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class, $route->getActionName());
        $this->assertSame('public.welcome', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());

        $welcomeUris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($candidate): string => $candidate->uri())
            ->filter(static fn (string $uri): bool => str_contains($uri, 'welcome'))
            ->values()
            ->all();

        $this->assertNotContains(
            'tenants/{tenant}/welcome',
            $welcomeUris,
            'A tenant-scoped Welcome alias would violate the canonical public 404 boundary.',
        );
    }

    public function test_public_welcome_renders_real_workflow_navigation_and_target_without_mutation(): void
    {
        $this->get('/welcome')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="#workflow"', false)
            ->assertSee('id="workflow"', false)
            ->assertSee('SIGNAL → DECISION → EXECUTION', false)
            ->assertSee('Observe')
            ->assertSee('Understand')
            ->assertSee('Review')
            ->assertSee('Execute');

        $this->post('/welcome')->assertStatus(405);
    }

    public function test_focused_control_does_not_claim_sibling_welcome_operations(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringNotContainsString('AIMW-CONT-B1E9E1D6B0', $view);
        $this->assertStringNotContainsString('AIMW-CONT-6458D3030B', $view);
        $this->assertStringNotContainsString('AIMW-CONT-D4607F943C', $view);
        $this->assertStringNotContainsString('AIMW-CONT-C7CB5EE184', $view);
    }
}
