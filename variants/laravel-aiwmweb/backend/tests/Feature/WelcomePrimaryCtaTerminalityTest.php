<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomePrimaryCtaTerminalityTest extends TestCase
{
    // Final exact-head CI retrigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-DD56664FDA';

    public function test_source_and_laravel_bind_the_exact_primary_cta_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('href="@PrimaryCtaHref"', $source);
        $this->assertStringContainsString(
            'private string PrimaryCtaHref => _isAuthenticated ? "/" : "/register";',
            $source,
        );
        $this->assertStringContainsString('private string PrimaryCtaText => _isAuthenticated', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString(
            "href=\"{{ \$isAuthenticated ? '/' : route('register', [], false) }}\"",
            $view,
        );
        $this->assertStringContainsString("'Open workspace'", $view);
        $this->assertStringContainsString("'Start free trial'", $view);
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
    }

    public function test_anonymous_welcome_primary_cta_targets_registration_in_both_locales(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/register"', false)
            ->assertSee('Start free trial');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/register"', false)
            ->assertSee('ابدأ مجانًا');
    }

    public function test_authenticated_welcome_primary_cta_targets_workspace_in_both_locales(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/"', false)
            ->assertSee('Open workspace');

        $this->actingAs($user)
            ->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/"', false)
            ->assertSee('فتح مساحة العمل');
    }

    public function test_primary_cta_preserves_public_read_only_tenant_neutral_boundary(): void
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

        $user = User::factory()->create();
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $alpha->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/tenants/foreign/welcome')
            ->assertNotFound();

        $this->post('/welcome')->assertStatus(405);
    }

    public function test_primary_cta_marker_is_operation_specific(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-C7CB5EE184"', $view);
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-1456BE001F"', $view);
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="AIMW-BILL-616576D8F7"'));
    }
}
