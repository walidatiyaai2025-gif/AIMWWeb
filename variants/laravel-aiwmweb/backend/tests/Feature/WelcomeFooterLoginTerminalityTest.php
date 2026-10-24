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

final class WelcomeFooterLoginTerminalityTest extends TestCase
{
    // Final exact-head CI retrigger after refreshed official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-247E791DA7';

    public function test_source_and_laravel_bind_the_exact_footer_login_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertStringContainsString(
            '<nav><a href="#product">Product</a><a href="#pricing">Pricing</a><a href="/login">Sign in</a></nav>',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString(
            '<a href="/login" data-canonical-operation="'.self::OPERATION_ID.'">Sign in</a>',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
    }

    public function test_footer_sign_in_always_targets_login_for_anonymous_visitors(): void
    {
        $this->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('Sign in');

        $this->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('Sign in');
    }

    public function test_footer_sign_in_remains_login_for_authenticated_visitors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/welcome?lang=en')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('Sign in');

        $this->actingAs($user)
            ->get('/welcome?lang=ar')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('Sign in');
    }

    public function test_footer_login_preserves_public_read_only_tenant_neutral_boundary(): void
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

    public function test_footer_login_marker_is_operation_specific(): void
    {
        $view = (string) file_get_contents(resource_path('views/ai/welcome.blade.php'));

        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-C7CB5EE184"', $view);
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-1456BE001F"', $view);
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="AIMW-CONT-DD56664FDA"'));
    }
}
