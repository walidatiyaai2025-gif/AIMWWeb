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

final class RolesPermissionsStartCreateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6CB3D0BE1B';

    public function test_source_start_create_semantics_are_bound_to_the_real_roles_workspace(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/roles-permissions-start-create-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="StartCreate"', $source);
        $this->assertStringContainsString('private Task StartCreate', $source);
        $this->assertStringContainsString('ResetEditor();', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.permissions.includes('roles.manage')", $control);
        $this->assertStringContainsString('context.api.roles', $control);
        $this->assertStringContainsString("method: 'POST'", $control);
        $this->assertStringContainsString('RolesPermissionsStartCreateControl', $pages);
        $this->assertStringContainsString("route.key === 'roles'", $pages);
        $this->assertStringContainsString("route.key === 'roles' && actionKey === 'roles.create'", $pages);

        $this->assertStringNotContainsString('AIMW-CONT-47C0477703', $control);
        $this->assertStringNotContainsString('AIMW-CONT-9CF12067E6', $control);
    }

    public function test_authorized_actor_creates_a_tenant_role_and_authoritative_reread_contains_it(): void
    {
        $actor = User::factory()->create(['email' => 'owner@example.test']);
        $tenant = $this->membership($actor, 'alpha', ['tenant.view', 'roles.view', 'roles.manage']);

        $this->actingAs($actor)
            ->postJson('/tenants/alpha/admin/roles', [
                'name' => 'editor',
                'permissions' => ['content.view', 'sites.view'],
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'editor')
            ->assertJsonPath('permissions.0', 'content.view')
            ->assertJsonPath('permissions.1', 'sites.view');

        $context = app(TenantContext::class);
        $context->activate($tenant);
        $role = Role::query()->where('name', 'editor')->firstOrFail();
        $this->assertTrue($role->permissions()->whereIn('name', ['content.view', 'sites.view'])->count() === 2);
        $context->forget();

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'editor']);
    }

    public function test_missing_roles_manage_and_foreign_tenant_fail_closed(): void
    {
        $actor = User::factory()->create();
        $this->membership($actor, 'alpha', ['tenant.view', 'roles.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($actor)
            ->postJson('/tenants/alpha/admin/roles', [
                'name' => 'blocked',
                'permissions' => [],
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/tenants/beta/admin/roles', [
                'name' => 'foreign',
                'permissions' => [],
            ])
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => "roles-start-create-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
