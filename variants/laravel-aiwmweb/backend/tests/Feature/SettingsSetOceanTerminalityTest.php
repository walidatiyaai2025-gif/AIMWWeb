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

final class SettingsSetOceanTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-659BEF8765';

    public function test_source_contract_is_set_ocean_through_app_theme_set(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'));
        $themeRuntime = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/wwwroot/js/app-theme.js'));

        $this->assertStringContainsString('class="theme-swatch ocean @ThemeClass("ocean")" @onclick="SetOcean"', $source);
        $this->assertStringContainsString('private Task SetOcean() => SetTheme("ocean");', $source);
        $this->assertStringContainsString('JS.InvokeAsync<string>("appTheme.set", value)', $source);
        $this->assertStringContainsString('const colorKey = "aiwm-color-theme";', $themeRuntime);
    }

    public function test_laravel_runtime_binds_exact_operation_to_shared_ocean_theme_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-set-ocean-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $shellTheme = (string) file_get_contents(resource_path('js/main-layout-parity-controls.tsx'));
        $shellCss = (string) file_get_contents(resource_path('js/main-layout-parity-controls.css'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme'", $control);
        $this->assertStringContainsString("document.documentElement.dataset.theme = 'ocean'", $control);
        $this->assertStringContainsString("window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'ocean')", $control);
        $this->assertStringContainsString('data-source-handler="SetOcean"', $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("import { SettingsSetOceanControl } from './settings-set-ocean-control';", $app);
        $this->assertStringContainsString('<SettingsSetOceanControl context={context} />', $app);
        $this->assertStringContainsString("{ key: 'ocean', en: 'Ocean', ar: 'أزرق', color: '#3b82f6' }", $shellTheme);
        $this->assertStringContainsString("html[data-theme='ocean']", $shellCss);
        $this->assertStringContainsString('--accent: #3b82f6', $shellCss);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_settings_surface_is_authenticated_permission_guarded_and_tenant_isolated(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/settings')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->get('/tenants/limited/settings')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view']);

        $this->actingAs($alpha)->get('/tenants/alpha/settings')->assertOk();
        $this->actingAs($alpha)->get('/tenants/beta/settings')->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "settings-ocean-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
