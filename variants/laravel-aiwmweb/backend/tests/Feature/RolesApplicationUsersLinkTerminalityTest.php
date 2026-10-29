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

final class RolesApplicationUsersLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-47C0477703';

    public function test_exact_canonical_input_is_the_pending_roles_application_users_link(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/admin/roles-permissions', $operation['route_screen']);
        $this->assertSame('/admin/application-users -> /admin/application-users', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_reference_source_and_laravel_control_preserve_exact_navigation_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/roles-application-users-link-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('href="/admin/application-users"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            "tenantUrl(context.tenant.slug, '/admin/application-users')",
            $control,
        );
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('users.view')", $control);
        $this->assertStringContainsString(
            "import { RolesApplicationUsersLinkControl } from './roles-application-users-link-control';",
            $pages,
        );
        $this->assertStringContainsString("route.key === 'roles'", $pages);
        $this->assertStringContainsString('<RolesApplicationUsersLinkControl context={context} />', $pages);
        $this->assertStringContainsString(
            '<Route path="admin/roles-permissions" element={<RouteElement route={workspaceRoutes.find((route) => route.key === \'roles\')!} />} />',
            $app,
        );
        $this->assertStringContainsString(
            "r('application-users', '/admin/users'",
            $core,
        );
        $this->assertStringContainsString("permission: 'users.view'", $core);
        $this->assertStringContainsString(
            "Route::get('/admin/application-users', 'redirect')",
            $routes,
        );
        $this->assertStringContainsString(
            "defaults('workspace_target', '/admin/users')",
            $routes,
        );
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('useMutation', $control);
        $this->assertStringNotContainsString('window.location', $control);
    }

    public function test_authorized_member_reaches_real_application_users_workspace(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha-roles-users-link', ['tenant.view', 'roles.view', 'users.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha-roles-users-link/admin/roles-permissions')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha-roles-users-link/admin/application-users')
            ->assertRedirect('/tenants/alpha-roles-users-link/admin/users');

        $this->actingAs($user)
            ->get('/tenants/alpha-roles-users-link/admin/users')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha-roles-users-link/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha-roles-users-link')
            ->assertJsonFragment(['users.view']);
    }

    public function test_missing_users_permission_and_foreign_tenant_navigation_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited-roles-users-link', ['tenant.view', 'roles.view']);
        $this->withoutVite();

        $this->actingAs($limited)
            ->get('/tenants/limited-roles-users-link/admin/roles-permissions')
            ->assertOk();

        $this->actingAs($limited)
            ->get('/tenants/limited-roles-users-link/admin/application-users')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha-foreign-roles-users-link', ['tenant.view', 'roles.view', 'users.view']);
        Tenant::query()->create(['name' => 'Foreign Roles Users Link', 'slug' => 'foreign-roles-users-link']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign-roles-users-link/admin/application-users')
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/roles-application-users-link-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation_id'] ?? null);
        $this->assertSame('PENDING', $evidence['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope']['only_operation_claimed'] ?? null);
        $this->assertFalse((bool) ($evidence['scope']['aspnet_modified'] ?? true));
        $this->assertFalse((bool) ($evidence['scope']['generated_reconciliation_modified'] ?? true));
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

        $role = Role::query()->create(['name' => "roles-users-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
