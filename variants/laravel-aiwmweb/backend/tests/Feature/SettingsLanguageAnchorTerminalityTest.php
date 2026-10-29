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

final class SettingsLanguageAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-041B5ED720';

    public function test_source_semantics_are_the_settings_language_anchor(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );

        $this->assertStringContainsString('<a href="#language">', $source);
        $this->assertStringContainsString('<section id="language"', $source);
        $this->assertStringContainsString('Arabic and English', $source);
    }

    public function test_laravel_control_targets_the_real_language_section_without_external_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-language-anchor-control.tsx'));
        $settings = (string) file_get_contents(resource_path('js/settings-ai-providers-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('href="#language"', $control);
        $this->assertStringContainsString('aria-controls="language"', $control);
        $this->assertStringContainsString('id="language"', $settings);
        $this->assertStringContainsString('<SettingsLanguageAnchorControl context={context} />', $app);

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
