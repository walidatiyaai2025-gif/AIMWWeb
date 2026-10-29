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

final class SettingsDatabaseAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-932A09E4A9';

    public function test_source_semantics_are_the_settings_database_fragment_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );

        $this->assertStringContainsString('<a href="#database">', $source);
        $this->assertStringContainsString('<section id="database"', $source);
        $this->assertStringContainsString('Application database provider', $source);
    }

    public function test_laravel_control_has_a_real_same_page_target_and_no_mutation_path(): void
    {
        $control = (string) file_get_contents(resource_path('js/settings-database-anchor-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("SETTINGS_DATABASE_TARGET_ID = 'database'", $control);
        $this->assertStringContainsString("href={'#' + SETTINGS_DATABASE_TARGET_ID}", $control);
        $this->assertStringContainsString('id={SETTINGS_DATABASE_TARGET_ID}', $control);
        $this->assertStringContainsString('aria-controls={SETTINGS_DATABASE_TARGET_ID}', $control);
        $this->assertStringContainsString('<SettingsDatabaseAnchorControl context={context} />', $app);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/system-health')", $control);

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
        $this->membership($alpha, 'alpha-database', ['tenant.view'], 'Alpha Database Settings');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta-database', ['tenant.view'], 'Beta Database Settings');

        $this->actingAs($alpha)
            ->get('/tenants/alpha-database/settings')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta-database/settings')
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
