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

final class SettingsAppearanceAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-918BE3DBF1';

    public function test_source_semantics_are_the_settings_appearance_fragment_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );

        $this->assertStringContainsString('<a href="#appearance">', $source);
        $this->assertStringContainsString('<section id="appearance"', $source);
        $this->assertStringContainsString('Appearance and colors', $source);
        $this->assertStringContainsString('Mode and theme', $source);
    }

    public function test_laravel_anchor_targets_the_real_appearance_control_without_mutation(): void
    {
        $anchor = (string) file_get_contents(resource_path('js/settings-appearance-anchor-control.tsx'));
        $appearance = (string) file_get_contents(resource_path('js/settings-appearance-light-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $anchor);
        $this->assertStringContainsString("SETTINGS_APPEARANCE_TARGET_ID = 'appearance'", $anchor);
        $this->assertStringContainsString("href={'#' + SETTINGS_APPEARANCE_TARGET_ID}", $anchor);
        $this->assertStringContainsString('aria-controls={SETTINGS_APPEARANCE_TARGET_ID}', $anchor);
        $this->assertStringContainsString('id={SETTINGS_APPEARANCE_TARGET_ID}', $appearance);
        $this->assertStringContainsString('data-settings-section="appearance"', $appearance);
        $this->assertStringContainsString('<SettingsAppearanceAnchorControl context={context} />', $app);
        $this->assertStringContainsString('<SettingsAppearanceLightControl context={context} />', $app);

        $this->assertStringNotContainsString('apiRequest', $anchor);
        $this->assertStringNotContainsString('fetch(', $anchor);
        $this->assertStringNotContainsString('POST', $anchor);
        $this->assertStringNotContainsString('PATCH', $anchor);
        $this->assertStringNotContainsString('DELETE', $anchor);
    }

    public function test_settings_surface_rejects_foreign_tenant_context(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha-appearance', ['tenant.view'], 'Alpha Appearance Settings');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta-appearance', ['tenant.view'], 'Beta Appearance Settings');

        $this->actingAs($alpha)
            ->get('/tenants/alpha-appearance/settings')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta-appearance/settings')
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
