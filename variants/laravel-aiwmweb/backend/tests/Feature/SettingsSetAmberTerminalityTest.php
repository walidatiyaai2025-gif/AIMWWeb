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

final class SettingsSetAmberTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-CF4BE4EC3B';

    public function test_source_contract_is_set_amber_through_app_theme_set(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $themeRuntime = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/wwwroot/js/app-theme.js'),
        );

        $this->assertStringContainsString('class="theme-swatch amber @ThemeClass("amber")" @onclick="SetAmber"', $source);
        $this->assertStringContainsString('private Task SetAmber() => SetTheme("amber");', $source);
        $this->assertStringContainsString('JS.InvokeAsync<string>("appTheme.set", value)', $source);
        $this->assertStringContainsString('const colorKey = "aiwm-color-theme";', $themeRuntime);
        $this->assertStringContainsString('document.documentElement.setAttribute("data-theme", theme)', $themeRuntime);
        $this->assertStringContainsString('localStorage.setItem(colorKey, theme)', $themeRuntime);
    }

    public function test_laravel_runtime_binds_exact_operation_to_source_equivalent_local_theme_state(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-set-amber-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme'", $control);
        $this->assertStringContainsString("document.documentElement.dataset.theme = 'amber'", $control);
        $this->assertStringContainsString("window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'amber')", $control);
        $this->assertStringContainsString('data-source-handler="SetAmber"', $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("import { SettingsSetAmberControl } from './settings-set-amber-control';", $app);
        $this->assertStringContainsString('<SettingsSetAmberControl context={context} />', $app);
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
        $role = Role::query()->create(['name' => "settings-amber-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
