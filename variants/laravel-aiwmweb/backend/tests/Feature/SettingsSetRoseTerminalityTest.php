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

final class SettingsSetRoseTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6700342002';

    public function test_source_contract_is_set_rose_through_app_theme_set(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $themeRuntime = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/wwwroot/js/app-theme.js'),
        );

        $this->assertStringContainsString('class="theme-swatch rose @ThemeClass("rose")" @onclick="SetRose"', $source);
        $this->assertStringContainsString('private Task SetRose() => SetTheme("rose");', $source);
        $this->assertStringContainsString('JS.InvokeAsync<string>("appTheme.set", value)', $source);
        $this->assertStringContainsString('const colorKey = "aiwm-color-theme";', $themeRuntime);
        $this->assertStringContainsString('document.documentElement.setAttribute("data-theme", theme)', $themeRuntime);
        $this->assertStringContainsString('localStorage.setItem(colorKey, theme)', $themeRuntime);
    }

    public function test_laravel_runtime_binds_exact_operation_to_shared_rose_theme_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-set-rose-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $shellTheme = (string) file_get_contents(resource_path('js/main-layout-parity-controls.tsx'));
        $shellCss = (string) file_get_contents(resource_path('js/main-layout-parity-controls.css'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme'", $control);
        $this->assertStringContainsString("document.documentElement.dataset.theme = 'rose'", $control);
        $this->assertStringContainsString("window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'rose')", $control);
        $this->assertStringContainsString('data-source-handler="SetRose"', $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("import { SettingsSetRoseControl } from './settings-set-rose-control';", $app);
        $this->assertStringContainsString('<SettingsSetRoseControl context={context} />', $app);

        $this->assertStringContainsString("const THEME_KEY = 'aiwm-color-theme'", $shellTheme);
        $this->assertStringContainsString("{ key: 'rose', en: 'Rose', ar: 'وردي', color: '#f43f5e' }", $shellTheme);
        $this->assertStringContainsString("html[data-theme='rose']", $shellCss);
        $this->assertStringContainsString('--accent: #f43f5e', $shellCss);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_settings_surface_is_authenticated_permission_guarded_and_tenant_isolated(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/settings')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)
            ->get('/tenants/limited/settings')
            ->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view']);

        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view']);

        $this->actingAs($alpha)
            ->get('/tenants/alpha/settings')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($alpha)
            ->get('/tenants/beta/settings')
            ->assertNotFound();

        $context = $this->actingAs($alpha)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->json();

        $this->assertSame('alpha', data_get($context, 'tenant.slug'));
        $this->assertContains('tenant.view', $context['permissions'] ?? []);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "settings-rose-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
