<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SitesServerPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_sites_paging_is_stable_bounded_filtered_and_legacy_array_survives(): void
    {
        [$user, $tenant] = $this->member('sites-page-alpha', ['tenant.view', 'sites.view']);
        [$otherUser, $other] = $this->member('sites-page-beta', ['tenant.view', 'sites.view']);
        $ctx = app(TenantContext::class);

        $ctx->activate($tenant);
        for ($i = 1; $i <= 28; $i++) {
            Site::query()->create([
                'name' => sprintf('Alpha %02d', $i),
                'url' => 'https://alpha-'.$i.'.example.test',
                'status' => 'active',
                'connection_status' => $i <= 12 ? 'connected' : ($i <= 20 ? 'failed' : 'unknown'),
            ]);
        }
        $ctx->activate($other);
        for ($i = 1; $i <= 3; $i++) {
            Site::query()->create([
                'name' => 'Beta '.$i,
                'url' => 'https://beta-'.$i.'.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
        }
        $ctx->forget();

        // Legacy no-query consumers (site selectors, onboarding) still receive
        // a complete tenant-owned JSON array. Paged screens opt in explicitly.
        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites')
            ->assertOk()->assertJsonCount(28);

        $first = $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 28)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('meta.connected_count', 12)
            ->assertJsonPath('meta.review_count', 8)
            ->assertJsonCount(10, 'data');
        $second = $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=2&per_page=10')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(10, 'data');
        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $secondIds = collect($second->json('data'))->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));

        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=1&per_page=10&connection_filter=connected')
            ->assertOk()->assertJsonPath('total', 12)->assertJsonPath('last_page', 2)
            ->assertJsonCount(10, 'data');
        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=1&per_page=10&connection_filter=review')
            ->assertOk()->assertJsonPath('total', 8)->assertJsonPath('last_page', 1)
            ->assertJsonCount(8, 'data');
        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=99&per_page=10')
            ->assertOk()->assertJsonPath('current_page', 3)->assertJsonCount(8, 'data');

        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=1&per_page=500')
            ->assertUnprocessable();
        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-alpha/sites?page=1&connection_filter=not-real')
            ->assertUnprocessable();
        $this->actingAs($user)
            ->getJson('/api/tenants/sites-page-beta/sites?page=1&per_page=10')
            ->assertNotFound();
        // The other tenant has independent totals, never Alpha's aggregates.
        $this->actingAs($otherUser)
            ->getJson('/api/tenants/sites-page-beta/sites?page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('meta.connected_count', 3);
    }

    public function test_sites_paging_requires_auth_and_tenant_view(): void
    {
        $this->getJson('/api/tenants/sites-page-noauth/sites?page=1')->assertUnauthorized();
        [$limited, $tenant] = $this->member('sites-page-noauth', ['sites.view']);
        $this->actingAs($limited)
            ->getJson('/api/tenants/sites-page-noauth/sites?page=1')
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Tenant} */
    private function member(string $slug, array $permissions): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => $slug]);
        $ctx = app(TenantContext::class);
        $ctx->activate($tenant);
        try {
            $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
            $role = Role::query()->create(['name' => $slug.'-'.$user->id]);
            foreach ($permissions as $name) {
                $permission = Permission::query()->firstOrCreate(['name' => $name]);
                $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
            }
            $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        } finally {
            $ctx->forget();
        }

        return [$user, $tenant];
    }
}
