<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReadWorkspaceSharedShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_billing_support_renders_bilingual_shared_shell_and_stays_read_only(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $previousLocale = app()->getLocale();

        try {
            foreach (['en' => ['ltr', 'Billing support', 'Support safety'], 'ar' => ['rtl', 'دعم الفوترة', 'سلامة دعم الفوترة']] as $locale => [$direction, $title, $safety]) {
                app()->setLocale($locale);

                $response = $this->actingAs($admin)
                    ->get('/admin/billing-support')
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                    ->assertSee('class="app-shell workspace-read-shell"', false)
                    ->assertSee('data-dashboard-shell="aiwmweb"', false)
                    ->assertSee('class="sidebar"', false)
                    ->assertSee('class="topbar"', false)
                    ->assertSee('id="workspace-read-content"', false)
                    ->assertSee('class="breadcrumb"', false)
                    ->assertSee('aria-current="page"', false)
                    ->assertSee($title)
                    ->assertSee($safety)
                    ->assertSee('data-canonical-operation="AIMW-BILL-5811B45F89"', false)
                    ->assertDontSee('<form', false)
                    ->assertDontSee('<button', false)
                    ->assertDontSee('name="tenant', false);
                $response->assertSee('href="/admin/billing-support"', false);
            }
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_authorized_tenant_admin_sessions_render_same_ar_en_shell_with_safe_tenant_links(): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'alpha'], ['name' => 'Alpha']);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create();
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'admin-sessions-read-shell']);
        foreach (['tenant.view', 'members.manage'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        $previousLocale = app()->getLocale();

        try {
            foreach (['en' => ['ltr', 'Application sessions'], 'ar' => ['rtl', 'جلسات التطبيق']] as $locale => [$direction, $title]) {
                app()->setLocale($locale);

                $response = $this->actingAs($user)
                    ->get('/tenants/alpha/admin/sessions')
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                    ->assertSee('class="app-shell workspace-read-shell"', false)
                    ->assertSee('data-dashboard-shell="aiwmweb"', false)
                    ->assertSee('class="sidebar"', false)
                    ->assertSee('class="topbar"', false)
                    ->assertSee('id="workspace-read-content"', false)
                    ->assertSee('aria-current="page"', false)
                    ->assertSee($title)
                    ->assertSee('href="/tenants/alpha/settings/sessions"', false)
                    ->assertSee('data-canonical-operation="AIMW-CONT-6B051BD8C0"', false)
                    ->assertDontSee('href="/tenants/beta/', false);
            }
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_billing_support_denies_guests_and_non_platform_administrators(): void
    {
        $this->get('/admin/billing-support')->assertRedirect('/login');

        $user = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($user)->get('/admin/billing-support')->assertForbidden();
    }

    public function test_both_views_reuse_compiled_app_styles_and_shared_mobile_components(): void
    {
        foreach (['admin/application-sessions', 'billing/admin-support'] as $view) {
            $source = (string) file_get_contents(resource_path("views/{$view}.blade.php"));

            $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
            $this->assertStringContainsString("@include('layouts.partials.workspace-read-styles')", $source);
            $this->assertStringContainsString("@include('layouts.partials.workspace-read-sidebar')", $source);
            $this->assertStringContainsString("@include('layouts.partials.workspace-read-topbar')", $source);
            $this->assertStringContainsString('data-dashboard-shell="aiwmweb"', $source);
        }

        $styles = (string) file_get_contents(resource_path('views/layouts/partials/workspace-read-styles.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/layouts/partials/workspace-read-sidebar.blade.php'));
        $this->assertStringContainsString('@media (max-width: 760px)', $styles);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $styles);
        $this->assertStringContainsString(':focus-visible', $styles);
        $this->assertStringContainsString('aria-current="page"', $sidebar);
        $this->assertStringContainsString('$shellLinks', $sidebar);
    }
}
