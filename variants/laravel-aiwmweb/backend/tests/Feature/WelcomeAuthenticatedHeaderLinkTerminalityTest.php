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

final class WelcomeAuthenticatedHeaderLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-9EDE4C1141';

    public function test_source_and_laravel_bind_the_exact_auth_aware_header_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString('@page "/welcome"', $source);
        $this->assertStringContainsString('href="@(_isAuthenticated ? "/" : "/login")"', $source);
        $this->assertStringContainsString('_isAuthenticated ? (L.IsArabic ? "لوحة التحكم" : "Dashboard")', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString("href=\"{{ \$isAuthenticated ? '/' : '/login' }}\"", $view);
        $this->assertStringContainsString("'Dashboard'", $view);
        $this->assertStringContainsString("'Sign in'", $view);
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
    }

    public function test_anonymous_welcome_keeps_sign_in_destination_in_both_locales(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('>Sign in</a>', false);

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('>تسجيل الدخول</a>', false);
    }

    public function test_authenticated_welcome_exposes_dashboard_destination_in_both_locales(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/"', false)
            ->assertSee('>Dashboard</a>', false);

        $this->actingAs($user)
            ->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/"', false)
            ->assertSee('>لوحة التحكم</a>', false);
    }

    public function test_control_preserves_the_existing_public_read_only_tenant_neutral_boundary(): void
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

    public function test_operation_marker_is_specific_and_does_not_claim_sibling_welcome_controls(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-C7CB5EE184"', $view);
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="AIMW-CONT-DD56664FDA"'));
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="AIMW-CONT-3C205B1901"'));
    }
}
