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

final class SettingsOpenAccessibilityTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-C49C37E2C9';

    public function test_source_semantics_are_the_real_accessibility_center_open_action(): void
    {
        $settings = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $runtime = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/wwwroot/js/accessibility-center.js'),
        );

        $this->assertStringContainsString('@onclick="OpenAccessibility"', $settings);
        $this->assertStringContainsString("document.querySelector('.accessibility-launcher')?.click()", $settings);
        $this->assertStringContainsString('aiwm-accessibility-font', $runtime);
        $this->assertStringContainsString('aiwm-accessibility-density', $runtime);
        $this->assertStringContainsString('aiwm-accessibility-contrast', $runtime);
        $this->assertStringContainsString('aiwm-accessibility-motion', $runtime);
        $this->assertStringContainsString('data-font-scale', $runtime);
        $this->assertStringContainsString('data-reduced-motion', $runtime);
    }

    public function test_laravel_control_preserves_display_center_state_and_has_no_server_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-open-accessibility-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('aiwm-accessibility-font', $control);
        $this->assertStringContainsString('aiwm-accessibility-density', $control);
        $this->assertStringContainsString('aiwm-accessibility-contrast', $control);
        $this->assertStringContainsString('aiwm-accessibility-motion', $control);
        $this->assertStringContainsString("root.setAttribute('data-font-scale'", $control);
        $this->assertStringContainsString("root.setAttribute('data-density'", $control);
        $this->assertStringContainsString("root.setAttribute('data-contrast'", $control);
        $this->assertStringContainsString("root.setAttribute('data-reduced-motion'", $control);
        $this->assertStringContainsString('<SettingsOpenAccessibilityControl context={context} />', $app);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString("method: 'POST'", $control);
        $this->assertStringNotContainsString("method: 'PATCH'", $control);
        $this->assertStringNotContainsString("method: 'DELETE'", $control);
    }

    public function test_settings_surface_remains_tenant_scoped_for_the_client_only_display_center(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view']);

        $this->actingAs($alpha)
            ->get('/tenants/alpha/settings')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta/settings')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "settings-accessibility-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
