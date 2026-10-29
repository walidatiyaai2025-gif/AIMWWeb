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

final class SettingsSetDarkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-EDC262499D';

    public function test_source_semantics_are_the_settings_set_dark_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );

        $this->assertStringContainsString('@onclick="SetDark"', $source);
        $this->assertStringContainsString('private Task SetDark() => SetMode("dark");', $source);
        $this->assertStringContainsString('ModeClass("dark")', $source);
        $this->assertStringContainsString('Comfortable for long sessions', $source);
    }

    public function test_laravel_control_matches_real_local_appearance_state_and_shell_sync(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-appearance-light-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $shell = (string) file_get_contents(resource_path('js/components.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('applyAppearanceMode(\'dark\')', $control);
        $this->assertStringContainsString('window.localStorage.setItem(MODE_STORAGE_KEY, mode)', $control);
        $this->assertStringContainsString('document.documentElement.dataset.mode = mode', $control);
        $this->assertStringContainsString('window.dispatchEvent(new CustomEvent(APPEARANCE_MODE_EVENT', $control);
        $this->assertStringContainsString('aria-pressed={mode === \'dark\'}', $control);
        $this->assertStringContainsString('<SettingsAppearanceLightControl context={context} />', $app);
        $this->assertStringContainsString('window.addEventListener(\'aiwm:appearance-mode\', syncAppearanceMode)', $shell);

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
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Settings');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Settings');

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
