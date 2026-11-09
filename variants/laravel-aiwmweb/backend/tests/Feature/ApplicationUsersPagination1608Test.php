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

final class ApplicationUsersPagination1608Test extends TestCase
{
    use RefreshDatabase;

    public function test_membership_grid_pages_sql_results_and_never_counts_another_tenant(): void
    {
        $actor = User::factory()->create(['name' => 'Tenant Admin']);
        $tenant = $this->createTenantWithReader($actor, 'alpha');
        for ($i = 0; $i < 31; $i++) {
            $this->addMember($tenant, sprintf('Member %03d', $i));
        }

        $foreign = User::factory()->create(['name' => 'Foreign Admin']);
        $otherTenant = $this->createTenantWithReader($foreign, 'beta');
        $this->addMember($otherTenant, 'Foreign Secret Member');

        $before = DB::table('tenant_memberships')->count();
        $first = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 32)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data')
            ->assertDontSee('Foreign Secret Member');
        $second = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?page=2&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 32)
            ->assertJsonCount(7, 'data')
            ->assertDontSee('Foreign Secret Member');

        $ids = array_merge(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(32, array_unique($ids));

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?page=999999&per_page=25')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(7, 'data');

        // Old integrations without paging parameters keep the array envelope.
        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members')
            ->assertOk()
            ->assertJsonCount(32, 'data')
            ->assertJsonMissingPath('current_page');

        $this->assertSame($before, DB::table('tenant_memberships')->count());
    }

    public function test_search_is_literal_and_sorted_across_server_pages(): void
    {
        $actor = User::factory()->create(['name' => 'Tenant Owner']);
        $tenant = $this->createTenantWithReader($actor, 'alpha');
        for ($i = 0; $i < 27; $i++) {
            $this->addMember($tenant, sprintf('Search User %02d', $i));
        }
        $this->addMember($tenant, 'Percent 100% User');

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?search=search%20user&page=2&per_page=10')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(10, 'data');
        $this->assertSame('Search User 10', $response->json('data.0.user.name'));

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?search=%25&page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.user.name', 'Percent 100% User');
    }

    public function test_invalid_paging_and_foreign_tenant_access_fail_closed(): void
    {
        $actor = User::factory()->create();
        $this->createTenantWithReader($actor, 'alpha');
        $other = User::factory()->create();
        $this->createTenantWithReader($other, 'beta');

        foreach (['page=0', 'page=1000001', 'page=abc', 'per_page=200', 'per_page=0'] as $invalid) {
            $this->actingAs($actor)
                ->getJson('/tenants/alpha/admin/members?'.$invalid)
                ->assertUnprocessable();
        }

        $this->actingAs($actor)
            ->getJson('/tenants/beta/admin/members?page=1&per_page=25')
            ->assertNotFound();
    }

    private function createTenantWithReader(User $actor, string $slug): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $actor->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'member-reader-'.$slug]);
        foreach (['tenant.view', 'members.manage'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function addMember(Tenant $tenant, string $name): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $user = User::factory()->create(['name' => $name]);
        TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $context->forget();
    }
}
