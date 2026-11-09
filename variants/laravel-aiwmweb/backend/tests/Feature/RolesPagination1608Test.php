<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RolesPagination1608Test extends TestCase
{
    use RefreshDatabase;

    public function test_roles_have_authoritative_sql_pages_with_stable_order_and_legacy_compatibility(): void
    {
        [$tenant, $reader] = $this->tenantReader('roles-alpha');
        for ($i = 0; $i < 62; $i++) {
            $this->createRole($tenant, sprintf('role-%03d', $i));
        }

        [$otherTenant] = $this->tenantReader('roles-beta');
        $foreign = $this->createRole($otherTenant, 'foreign-secret-role');

        $before = DB::table('roles')->count();

        $first = $this->actingAs($reader)->getJson('/tenants/roles-alpha/admin/roles?page=1&per_page=25')
            ->assertOk()->assertJsonPath('total', 63)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data')
            ->assertDontSee('foreign-secret-role');

        $second = $this->actingAs($reader)->getJson('/tenants/roles-alpha/admin/roles?page=2&per_page=25')
            ->assertOk()->assertJsonCount(25, 'data');
        $third = $this->actingAs($reader)->getJson('/tenants/roles-alpha/admin/roles?page=3&per_page=25')
            ->assertOk()->assertJsonPath('current_page', 3)
            ->assertJsonCount(13, 'data');

        $ids = array_merge(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
            collect($third->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(63, array_unique($ids));
        $this->assertNotContains($foreign->id, $ids);

        $this->actingAs($reader)->getJson('/tenants/roles-alpha/admin/roles?page=1000000&per_page=25')
            ->assertOk()->assertJsonPath('current_page', 3)->assertJsonCount(13, 'data');

        $legacy = $this->actingAs($reader)->getJson('/tenants/roles-alpha/admin/roles')
            ->assertOk()->assertJsonCount(63, 'data')
            ->assertJsonMissingPath('current_page');
        $owner = collect($legacy->json('data'))->firstWhere('name', 'owner');
        $this->assertContains('tenant.view', $owner['permissions']);

        $this->assertSame($before, DB::table('roles')->count());
    }

    public function test_roles_name_filter_is_literal_case_insensitive_and_paged(): void
    {
        [$tenant, $reader] = $this->tenantReader('roles-search');
        for ($i = 0; $i < 26; $i++) {
            $this->createRole($tenant, sprintf('Search Role %02d', $i));
        }
        $this->createRole($tenant, 'Literal%Role');
        $this->createRole($tenant, 'Literal_Role');
        $this->createRole($tenant, 'Literal!Role');

        $second = $this->actingAs($reader)
            ->getJson('/tenants/roles-search/admin/roles?search=%20sEaRcH%20&page=2&per_page=10')
            ->assertOk()->assertJsonPath('total', 26)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(10, 'data');
        $this->assertSame('Search Role 10', $second->json('data.0.name'));

        foreach ([
            'search=%25' => 'Literal%Role',
            'search=%5F' => 'Literal_Role',
            'search=%21' => 'Literal!Role',
        ] as $query => $roleName) {
            $this->actingAs($reader)->getJson('/tenants/roles-search/admin/roles?'.$query.'&page=1&per_page=10')
                ->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.name', $roleName);
        }

        $this->actingAs($reader)
            ->getJson('/tenants/roles-search/admin/roles?search=nonexistent&page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(0, 'data');
    }

    public function test_roles_validate_page_bounds_and_never_cross_tenant_context(): void
    {
        [, $reader] = $this->tenantReader('roles-safe');
        $this->tenantReader('roles-foreign');

        foreach (['page=0', 'page=1000001', 'page=abc', 'per_page=0', 'per_page=200'] as $invalid) {
            $this->actingAs($reader)->getJson('/tenants/roles-safe/admin/roles?'.$invalid)
                ->assertUnprocessable();
        }

        $this->actingAs($reader)->getJson('/tenants/roles-foreign/admin/roles?page=1&per_page=25')
            ->assertNotFound();
    }

    public function test_guest_cannot_read_roles_page(): void
    {
        $this->getJson('/tenants/roles-safe/admin/roles?page=1&per_page=25')
            ->assertUnauthorized();
    }

    public function test_roles_workspace_uses_paged_queries_and_real_page_size_controls(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/AdminOperationsController.php'));

        $this->assertStringContainsString("route.key === 'roles' ? {", $frontend);
        $this->assertStringContainsString("per_page: String(rolesPageSize)", $frontend);
        $this->assertStringContainsString("setRolesPageSize(size)", $frontend);
        $this->assertStringContainsString("route.key === 'roles' ? rolesPageSize", $frontend);
        $this->assertStringContainsString('if ($request->hasAny', $controller);
        $this->assertStringContainsString('paginatedRoles(', $controller);
    }

    /** @return array{Tenant, User} */
    private function tenantReader(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'owner']);
        $permission = Permission::query()->firstOrCreate(['name' => 'tenant.view']);
        $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }

    private function createRole(Tenant $tenant, string $name): Role
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $role = Role::query()->create(['name' => $name]);
        $context->forget();

        return $role;
    }
}
