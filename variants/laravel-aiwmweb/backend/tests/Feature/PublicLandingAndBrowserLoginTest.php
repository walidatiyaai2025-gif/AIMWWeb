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
            ->assertSee('WORDPRESS OPERATIONS OS')
            ->assertSee('href="/login"', false)
            ->assertSee('dir="ltr"', false)
            ->assertDontSee('Laravel 13');

        $this->get('/?lang=ar')
            ->assertOk()
            ->assertSee('طريقة العمل')
            ->assertSee('تسجيل الدخول')
            ->assertSee('dir="rtl"', false);
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

    public function test_login_screen_renders_bilingual_tenant_neutral_shell_and_retains_safe_return_url(): void
    {
        foreach ([
            'en' => ['ltr', 'Sign in to continue to your workspace', 'العربية'],
            'ar' => ['rtl', 'سجّل الدخول للمتابعة إلى مساحة عملك', 'English'],
        ] as $locale => [$direction, $subtitle, $switchLanguage]) {
            $this->get('/login?lang='.$locale.'&returnUrl='.urlencode('/tenants/owned/workspace?tab=ops'))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                ->assertSee('data-tenant-neutral="true"', false)
                ->assertSee('data-auth-shell="aiwmweb"', false)
                ->assertSee('name="robots" content="noindex,nofollow"', false)
                ->assertSee('name="returnUrl" value="/tenants/owned/workspace?tab=ops"', false)
                ->assertSee('name="password"', false)
                ->assertSee('name="_token"', false)
                ->assertSee('href="/login?lang='.($locale === 'ar' ? 'en' : 'ar').'&amp;returnUrl=%2Ftenants%2Fowned%2Fworkspace%3Ftab%3Dops"', false)
                ->assertSee($subtitle)
                ->assertSee($switchLanguage)
                ->assertDontSee('class="sidebar"', false);
        }

        $this->get('/login?lang=ar&returnUrl='.urlencode('//evil.example/steal'))
            ->assertOk()
            ->assertSee('name="returnUrl" value="/"', false)
            ->assertDontSee('evil.example');

        $source = (string) file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
        $this->assertStringContainsString('min-height: 44px', $source);
        $this->assertStringContainsString(':focus-visible', $source);
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

        // The redirect's error bag is the contract; array sessions need not persist through another request.
        $this->assertGuest();
    }

    public function test_existing_api_login_still_returns_json_for_api_consumers(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'password' => bcrypt('correct-password')]);

        $this->postJson('/api/login', ['email' => 'owner@example.test', 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@example.test');
    }

    public function test_new_free_trial_user_signs_in_from_browser_and_opens_tenant_dashboard(): void
    {
        $this->post('/register', [
            'username' => 'TrialNew',
            'password' => 'StrongPass9',
            'password_confirmation' => 'StrongPass9',
        ])->assertRedirect('/login?registered=true');

        $user = User::query()->where('normalized_username', 'TRIALNEW')->firstOrFail();
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('tenant')
            ->firstOrFail();
        $this->assertNotNull($membership->tenant);

        $this->post('/login', [
            'email' => 'TrialNew',
            'password' => 'StrongPass9',
        ])->assertRedirect('/tenants/'.$membership->tenant->slug);

        $this->assertAuthenticatedAs($user);
        $this->get('/tenants/'.$membership->tenant->slug)->assertOk();
    }
}
