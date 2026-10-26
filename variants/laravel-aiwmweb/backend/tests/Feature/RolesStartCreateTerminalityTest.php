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

final class RolesStartCreateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6CB3D0BE1B';

    public function test_source_start_create_semantics_are_wired_to_a_real_clean_create_state(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/roles-start-create-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="StartCreate"', $source);
        $this->assertStringContainsString('private Task StartCreate', $source);
        $this->assertStringContainsString('ResetEditor();', $source);
        $this->assertStringContainsString('_editingExisting = false;', $source);
        $this->assertStringContainsString('_name = _displayNameEnglish = _displayNameArabic = string.Empty;', $source);
        $this->assertStringContainsString('_selectedPermissions.Clear();', $source);
        $this->assertStringContainsString('Disabled="@_working"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('context.permissions.includes(\'roles.manage\')', $control);
        $this->assertStringContainsString('const rolesEndpoint = context.api.roles;', $control);
        $this->assertStringContainsString('setName(\'\');', $control);
        $this->assertStringContainsString('setError(null);', $control);
        $this->assertStringContainsString('apiRequest(rolesEndpoint', $control);
        $this->assertStringContainsString('method: \'POST\'', $control);
        $this->assertStringContainsString('permissions: []', $control);
        $this->assertStringContainsString('\'＋ New role\'', $control);
        $this->assertStringContainsString('\'＋ دور جديد\'', $control);

        $this->assertStringContainsString(
            'import { RolesStartCreateControl } from \'./roles-start-create-control\';',
            $pages,
        );
        $this->assertStringContainsString('route.key === \'roles\'', $pages);
        $this->assertStringContainsString('<RolesStartCreateControl', $pages);
        $this->assertStringContainsString(
            '!(route.key === \'roles\' && actionKey === \'roles.create\')',
            $pages,
        );
    }

    public function test_supporting_role_create_path_persists_then_authoritative_read_reconciles(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser(
            'alpha-start-create',
            ['tenant.view', 'roles.view', 'roles.manage'],
        );

        $this->actingAs($authorized->user)
            ->postJson('/tenants/alpha-start-create/admin/roles', [
                'name' => 'support-agent',
                'permissions' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'support-agent')
            ->assertJsonPath('permissions', []);

        $this->assertDatabaseHas('roles', [
            'tenant_id' => $alpha->id,
            'name' => 'support-agent',
        ]);

        $this->actingAs($authorized->user)
            ->getJson('/tenants/alpha-start-create/admin/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'support-agent']);
    }

    public function test_missing_manage_permission_and_foreign_tenant_fail_closed(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser(
            'alpha-start-create-deny',
            ['tenant.view', 'roles.view', 'roles.manage'],
        );
        $limited = $this->member(
            $alpha,
            'limited-start-create@example.test',
            ['tenant.view', 'roles.view'],
        );
        $beta = Tenant::query()->create([
            'name' => 'Beta Start Create',
            'slug' => 'beta-start-create',
        ]);
        $this->withoutVite();

        $this->actingAs($limited->user)
            ->postJson('/tenants/alpha-start-create-deny/admin/roles', [
                'name' => 'blocked-role',
                'permissions' => [],
            ])
            ->assertForbidden();

        $this->actingAs($authorized->user)
            ->get('/tenants/beta-start-create/admin/roles-permissions')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function tenantWithUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $membership = $this->member($tenant, "{$slug}-owner@example.test", $permissions);

        return [$tenant, $membership];
    }

    /** @param list<string> $permissions */
    private function member(Tenant $tenant, string $email, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => $email]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => "roles-start-create-{$tenant->slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return $membership;
    }
}
