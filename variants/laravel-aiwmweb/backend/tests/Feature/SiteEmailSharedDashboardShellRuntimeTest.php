<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SiteEmailSharedDashboardShellRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_and_diagnostics_share_real_authenticated_ar_en_dashboard_shell_navigation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $site = $this->site($membership, 'Alpha Mail Site');
        $this->withoutVite();

        $originalLocale = app()->getLocale();
        try {
            foreach ([
                'en' => ['ltr', 'Site email settings', 'Site email diagnostics', 'Sites'],
                'ar' => ['rtl', 'إعدادات البريد للموقع', 'تشخيص بريد الموقع', 'المواقع'],
            ] as $locale => [$direction, $settingsTitle, $diagnosticsLink, $sitesLink]) {
                app()->setLocale($locale);
                $settings = $this->actingAs($user)
                    ->get("/tenants/alpha/sites/{$site->id}/email-settings")
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                    ->assertSee('data-dashboard-shell="aiwmweb"', false)
                    ->assertSee('class="sidebar site-email-sidebar"', false)
                    ->assertSee('class="topbar site-email-topbar"', false)
                    ->assertSee('id="site-email-content"', false)
                    ->assertSee('class="main-area"', false)
                    ->assertSee('class="breadcrumb"', false)
                    ->assertSee('aria-current="page"', false)
                    ->assertSee($settingsTitle)
                    ->assertSee($diagnosticsLink)
                    ->assertSee($sitesLink)
                    ->assertSee('method="post"', false)
                    ->assertSee('name="_token"', false)
                    ->assertSee('name="password" value=""', false);

                $settings->assertSee(
                    "/tenants/alpha/sites/{$site->id}/email-diagnostics",
                    false,
                );

                $diagnostics = $this->actingAs($user)
                    ->get("/tenants/alpha/sites/{$site->id}/email-diagnostics")
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                    ->assertSee('data-dashboard-shell="aiwmweb"', false)
                    ->assertSee('class="sidebar site-email-sidebar"', false)
                    ->assertSee('class="topbar site-email-topbar"', false)
                    ->assertSee('id="site-email-content"', false)
                    ->assertSee('class="breadcrumb"', false)
                    ->assertSee('aria-current="page"', false)
                    ->assertSee($diagnosticsLink)
                    ->assertSee('name="_token"', false)
                    ->assertSee("href=\"/tenants/alpha/sites/{$site->id}/email-settings\"", false);
                $diagnostics->assertDontSee('name="password"', false);
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_module_route_with_no_selected_site_renders_safe_shell_without_invalid_site_navigation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $this->withoutVite();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/module/site-email-settings')
            ->assertOk()
            ->assertSee('data-dashboard-shell="aiwmweb"', false)
            ->assertSee('id="site-email-content"', false)
            ->assertSee('name="site"', false)
            ->assertSee('Site email settings')
            ->assertDontSee('/sites//email-diagnostics', false);
    }

    public function test_both_views_reference_the_same_compiled_asset_and_reusable_responsive_components(): void
    {
        foreach (['email-settings', 'email-diagnostics'] as $view) {
            $source = (string) file_get_contents(resource_path("views/sites/{$view}.blade.php"));

            $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
            $this->assertStringContainsString("@include('sites.partials.email-shell-styles')", $source);
            $this->assertStringContainsString("@include('sites.partials.email-sidebar'", $source);
            $this->assertStringContainsString("@include('sites.partials.email-topbar'", $source);
            $this->assertStringContainsString('data-dashboard-shell="aiwmweb"', $source);
            $this->assertStringNotContainsString('password_ciphertext', $source);
        }

        $styles = (string) file_get_contents(resource_path('views/sites/partials/email-shell-styles.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/sites/partials/email-sidebar.blade.php'));

        $this->assertStringContainsString('data-aiwm-site-email-shell-styles', $styles);
        $this->assertStringContainsString('@media (max-width: 760px)', $styles);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $styles);
        $this->assertStringContainsString(':focus-visible', $styles);
        $this->assertStringContainsString('aria-current="page"', $sidebar);
        $this->assertStringContainsString("route('canonical.workspace.site-email-diagnostics'", $sidebar);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-email-shell-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://alpha-mail.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
