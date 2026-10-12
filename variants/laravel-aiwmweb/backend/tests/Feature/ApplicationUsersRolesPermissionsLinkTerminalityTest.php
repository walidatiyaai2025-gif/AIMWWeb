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

final class ApplicationUsersRolesPermissionsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-1A08B49164';

    public function test_reference_source_and_laravel_control_preserve_exact_navigation_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/application-users-roles-permissions-link-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString('href="/admin/roles-permissions"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            "tenantUrl(context.tenant.slug, '/admin/roles-permissions')",
            $control,
        );
        $this->assertStringContainsString("context.permissions.includes('roles.view')", $control);
        $this->assertStringContainsString('ApplicationUsersRolesPermissionsLinkControl', $pages);
        $this->assertStringContainsString("route.key === 'application-users'", $pages);
        $this->assertStringContainsString(
            '<Route path="admin/roles-permissions" element={<RouteElement route={workspaceRoutes.find((route) => route.key === \'roles\')!} />} />',
            $app,
        );
        $this->assertStringContainsString(
            "r('roles', '/admin/roles'",
            $core,
        );
        $this->assertStringContainsString("permission: 'roles.view'", $core);
        $this->assertStringNotContainsString('AIMW-CONT-E38ADF8481', $control);
        $this->assertStringNotContainsString('AIMW-CONT-47C0477703', $control);
        $this->assertStringNotContainsString('AIMW-CONT-9CF12067E6', $control);
    }

    public function test_authorized_member_can_open_real_roles_permissions_workspace_and_read_roles(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'users.view', 'roles.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/application-users')
            ->assertRedirect('/tenants/alpha/admin/users');

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/roles-permissions')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonFragment(['roles.view']);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/admin/roles')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_foreign_tenant_navigation_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'users.view', 'roles.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/beta/admin/roles-permissions')
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/tenants/beta/admin/roles')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => "application-users-role-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
