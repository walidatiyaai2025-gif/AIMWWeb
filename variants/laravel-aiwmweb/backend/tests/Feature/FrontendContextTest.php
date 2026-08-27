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

class FrontendContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_context_is_tenant_scoped_and_exposes_no_fake_capabilities(): void
    {
        $user = User::factory()->create(['name' => 'Frontend User', 'email' => 'frontend@example.test']);
        $alpha = $this->tenantMembership($user, 'alpha', ['tenant.view', 'content.view']);
        $this->tenantMembership($user, 'beta', ['tenant.view']);
        $stranger = User::factory()->create();
        $this->tenantMembership($stranger, 'private', ['tenant.view']);

        $response = $this->actingAs($user)->getJson('/tenants/alpha/context');

        $response->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('user.email', 'frontend@example.test')
            ->assertJsonCount(2, 'tenants')
            ->assertJsonFragment(['slug' => 'alpha', 'name' => 'Alpha'])
            ->assertJsonFragment(['slug' => 'beta', 'name' => 'Beta'])
            ->assertJsonMissing(['slug' => 'private'])
            ->assertJsonFragment(['tenant.view'])
            ->assertJsonFragment(['content.view'])
            ->assertJsonPath('connectors', [])
            ->assertJsonPath('capabilities', [])
            ->assertJsonPath('api', [])
            ->assertJsonPath('actions', []);

        $this->assertSame($alpha->tenant_id, Tenant::query()->where('slug', 'alpha')->value('id'));
    }

    public function test_frontend_context_cannot_switch_into_an_unowned_tenant(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $this->tenantMembership($owner, 'alpha', ['tenant.view']);
        $this->tenantMembership($outsider, 'beta', ['tenant.view']);

        $this->actingAs($owner)->getJson('/tenants/beta/context')->assertNotFound();
    }

    public function test_spa_route_is_guarded_by_real_tenant_permission(): void
    {
        $user = User::factory()->create();
        $this->tenantMembership($user, 'alpha', ['tenant.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/module/posts')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('AI WordPress Manager — Laravel');
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "member-{$slug}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership;
    }
}
