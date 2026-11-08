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

final class DashboardTenantEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_bridge_requires_auth_and_never_uses_tenant_context_without_an_explicit_tenant(): void
    {
        $route = Route::getRoutes()->match(Request::create('/dashboard', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class.'@dashboard', ltrim($route->getActionName(), '\\'));
        $this->assertSame('public.dashboard.bridge', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/dashboard')->assertStatus(405);
    }

    public function test_authenticated_welcome_ctas_use_bridge_without_disclosing_any_tenant_identifier(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'owned-dash-tenant');
        $this->membership(User::factory()->create(), 'foreign-dash-tenant');

        $response = $this->actingAs($user)->get('/welcome?lang=en&tenant=foreign-dash-tenant');
        $response->assertOk()->assertSee('href="/dashboard"', false);
        $response->assertDontSee('href="/"', false);
        $response->assertDontSee('owned-dash-tenant');
        $response->assertDontSee('foreign-dash-tenant');
    }

    public function test_exactly_one_active_membership_redirects_to_its_canonical_tenant_dashboard(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'my-live-workspace');
        $this->membership($user, 'suspended-workspace', 'suspended');
        $this->membership(User::factory()->create(), 'foreign-workspace');

        $this->actingAs($user)
            ->get('/dashboard?tenant=foreign-workspace&returnUrl=https://attacker.example')
            ->assertRedirect('/tenants/my-live-workspace');
    }

    public function test_multiple_active_memberships_require_visible_explicit_selection_and_only_show_owned_tenants(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'owned-alpha');
        $this->membership($user, 'owned-beta');
        $this->membership($user, 'suspended-one', 'suspended');
        $this->membership(User::factory()->create(), 'foreign-tenant');

        $response = $this->actingAs($user)->get('/dashboard?tenant=foreign-tenant');

        $response->assertOk()
            ->assertSee('Choose a workspace')
            ->assertSee('href="/tenants/owned-alpha"', false)
            ->assertSee('href="/tenants/owned-beta"', false)
            ->assertDontSee('href="/tenants/foreign-tenant"', false)
            ->assertDontSee('href="/tenants/suspended-one"', false);
    }

    public function test_multi_tenant_selector_renders_branded_bilingual_responsive_shell_without_unauthorized_navigation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'owned-alpha');
        $this->membership($user, 'owned-beta');
        $this->membership($user, 'suspended-one', 'suspended');
        $this->membership(User::factory()->create(), 'foreign-tenant');

        foreach (['en' => ['ltr', 'Choose a workspace'], 'ar' => ['rtl', 'اختر مساحة العمل']] as $locale => [$direction, $heading]) {
            $this->actingAs($user)
                ->withSession(['public_welcome_locale' => $locale])
                ->get('/dashboard?tenant=foreign-tenant')
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                ->assertSee('data-dashboard-shell="aiwmweb"', false)
                ->assertSee('data-tenant-neutral="true"', false)
                ->assertSee('class="selector-topbar"', false)
                ->assertSee('class="selector-list"', false)
                ->assertSee('href="/tenants/owned-alpha"', false)
                ->assertSee('href="/tenants/owned-beta"', false)
                ->assertDontSee('href="/tenants/foreign-tenant"', false)
                ->assertDontSee('href="/tenants/suspended-one"', false)
                ->assertDontSee('class="sidebar"', false)
                ->assertSee($heading);
        }

        $source = (string) file_get_contents(resource_path('views/ai/choose-tenant.blade.php'));
        $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
        $this->assertStringContainsString('@media (max-width: 700px)', $source);
        $this->assertStringContainsString(':focus-visible', $source);
        $this->assertStringContainsString('name="robots" content="noindex,nofollow"', $source);
    }

    public function test_missing_or_inactive_memberships_fail_closed_instead_of_redirecting_to_public_root(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'expired-tenant', 'suspended');

        $this->actingAs($user)->get('/dashboard')->assertStatus(409);
    }

    private function membership(User $user, string $slug, string $status = 'active'): void
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
