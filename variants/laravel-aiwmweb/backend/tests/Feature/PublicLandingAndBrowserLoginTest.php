<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicLandingAndBrowserLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_landing_shows_features_without_login_or_fake_metrics(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('AI WordPress Manager')
            ->assertSee('المميزات')
            ->assertSee('Application Password')
            ->assertSee('href="'.url('/login').'"', false)
            ->assertDontSee('Laravel 13');

        $this->get('/?lang=en')
            ->assertOk()
            ->assertSee('Features')
            ->assertSee('How it works')
            ->assertSee('Sign in')
            ->assertSee('dir="ltr"', false);
    }

    public function test_login_route_supports_real_browser_post_and_keeps_json_api_separate(): void
    {
        $get = Route::getRoutes()->match(Request::create('/login', 'GET'));
        $post = Route::getRoutes()->match(Request::create('/login', 'POST'));

        $this->assertSame('login', $get->getName());
        $this->assertSame('login.store', $post->getName());
        $this->assertContains('throttle:10,1', $post->gatherMiddleware());
        $this->get('/login')->assertOk()->assertSee('action="'.route('login.store').'"', false);
    }

    public function test_browser_login_redirects_to_actual_owned_tenant_not_guessed_slug(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => bcrypt('correct-password')]);
        $tenant = Tenant::query()->create(['name' => 'WordPress Manager', 'slug' => 'wordpress-manager']);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $context->forget();

        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'correct-password'])
            ->assertRedirect('/tenants/wordpress-manager');
        $this->assertAuthenticatedAs($user);

        $this->get('/login')->assertRedirect('/tenants/wordpress-manager');
    }

    public function test_browser_login_rejects_external_return_url_and_never_redirects_to_external_host(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => bcrypt('correct-password')]);
        $tenant = Tenant::query()->create(['name' => 'Team', 'slug' => 'team']);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $context->forget();

        $this->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'correct-password',
            'returnUrl' => '//evil.example/steal',
        ])->assertRedirect('/tenants/team');
    }

    public function test_bad_browser_password_returns_login_form_not_json_user_data(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'password' => bcrypt('correct-password')]);
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->get('/login')->assertOk()->assertSee('Invalid email/username or password.');
        $this->assertGuest();
    }

    public function test_existing_api_login_still_returns_json_for_api_consumers(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'password' => bcrypt('correct-password')]);

        $this->postJson('/api/login', ['email' => 'owner@example.test', 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@example.test');
    }
}
