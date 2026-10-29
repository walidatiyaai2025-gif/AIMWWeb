<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\RolesPermissionsRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RolesPermissionsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-9CF12067E6';

    public function test_source_contract_and_explicit_laravel_route_match_open_render_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/RolesPermissions.razor'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('@page "/admin/roles-permissions"', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.SettingsManage', $source);
        $this->assertStringContainsString('_service.ListAsync()', $source);
        $this->assertStringContainsString('_userService.ListAsync()', $source);
        $this->assertStringContainsString('Loading roles', $source);
        $this->assertStringContainsString('No custom roles yet', $source);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/admin/roles-permissions', 'GET'),
        );

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.roles-permissions', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('tenant.view,roles.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->assertStringContainsString(
            '<Route path="admin/roles-permissions" element={<RouteElement route={workspaceRoutes.find((route) => route.key === \'roles\')!} />} />',
            $app,
        );
        $this->assertStringContainsString("r('roles', '/admin/roles'", $core);
        $this->assertStringContainsString("permission: 'roles.view'", $core);
        $this->assertStringContainsString('if (query.isLoading) return <LoadingState />;', $pages);
        $this->assertStringContainsString('<QueryError error={query.error} retry={() => query.refetch()} />', $pages);
        $this->assertStringContainsString('No sample rows are synthesized when the server returns an empty result.', $pages);
        $this->assertSame(self::OPERATION_ID, RolesPermissionsRouteServiceProvider::OPERATION_ID);
    }

    public function test_route_fails_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser('alpha', ['tenant.view', 'roles.view']);
        $limited = $this->member($alpha, 'limited@example.test', ['tenant.view']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->get('/tenants/alpha/admin/roles-permissions')
            ->assertRedirect('/login');

        $this->actingAs($limited->user)
            ->get('/tenants/alpha/admin/roles-permissions')
            ->assertForbidden();

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/admin/roles-permissions")
            ->assertNotFound();
    }

    public function test_authorized_route_renders_app_shell_and_reads_authoritative_tenant_roles_without_mutation(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser('alpha', ['tenant.view', 'roles.view']);
        $this->seedRole($alpha, 'alpha-editor');
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->seedRole($beta, 'beta-editor');
        $this->withoutVite();

        $beforeRoles = DB::table('roles')->count();

        $this->actingAs($authorized->user)
            ->get('/tenants/alpha/admin/roles-permissions')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($authorized->user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('api.roles', '/tenants/alpha/admin/roles')
            ->assertJsonFragment(['roles.view']);

        $response = $this->actingAs($authorized->user)
            ->getJson('/tenants/alpha/admin/roles')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertStringContainsString('alpha-editor', $response->getContent());
        $this->assertStringNotContainsString('beta-editor', $response->getContent());
        $this->assertSame($beforeRoles, DB::table('roles')->count());
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

        $role = Role::query()->create(['name' => "roles-route-{$tenant->slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return $membership;
    }

    private function seedRole(Tenant $tenant, string $name): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        Role::query()->create(['name' => $name]);
        $context->forget();
    }
}
