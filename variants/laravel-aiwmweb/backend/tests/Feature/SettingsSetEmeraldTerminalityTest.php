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

final class SettingsSetEmeraldTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-566A1899F1';

    public function test_source_semantics_are_the_settings_set_emerald_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $themeRuntime = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/wwwroot/js/app-theme.js'),
        );

        $this->assertStringContainsString('@onclick="SetEmerald"', $source);
        $this->assertStringContainsString('private Task SetEmerald() => SetTheme("emerald");', $source);
        $this->assertStringContainsString('ThemeClass("emerald")', $source);
        $this->assertStringContainsString('const colorKey = "aiwm-color-theme";', $themeRuntime);
        $this->assertStringContainsString('document.documentElement.setAttribute("data-theme", theme)', $themeRuntime);
    }

    public function test_laravel_control_reuses_the_real_shell_theme_contract_without_network_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-accent-theme-emerald-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $shellTheme = (string) file_get_contents(resource_path('js/main-layout-parity-controls.tsx'));
        $shellCss = (string) file_get_contents(resource_path('js/main-layout-parity-controls.css'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme'", $control);
        $this->assertStringContainsString("document.documentElement.dataset.theme = 'emerald'", $control);
        $this->assertStringContainsString("window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald')", $control);
        $this->assertStringContainsString('aria-pressed={active}', $control);
        $this->assertStringContainsString('<SettingsAccentThemeEmeraldControl context={context} />', $app);

        $this->assertStringContainsString("const THEME_KEY = 'aiwm-color-theme'", $shellTheme);
        $this->assertStringContainsString("html[data-theme='emerald']", $shellCss);
        $this->assertStringContainsString('--accent: #10b981', $shellCss);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_settings_surface_rejects_foreign_tenant_context(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Emerald Settings');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Emerald Settings');

        $this->actingAs($alpha)
            ->get('/tenants/alpha/settings')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta/settings')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
