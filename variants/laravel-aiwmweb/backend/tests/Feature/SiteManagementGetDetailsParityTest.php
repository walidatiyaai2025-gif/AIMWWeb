<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteManagementGetDetailsParityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-95AC5F28A7';

    public function test_get_details_projects_canonical_read_fields_without_secret_material(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, [
            'name' => 'Alpha Site',
            'url' => 'https://alpha.example.test',
            'home_url' => 'https://alpha.example.test/home',
            'wordpress_version' => '6.8.2',
            'language_code' => 'en_US',
            'connection_status' => 'connected',
            'last_verified_at' => '2026-09-06 12:34:56',
        ]);
        $updatedAt = $site->updated_at;

        $response = $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/'.$site->id)
            ->assertOk()
            ->assertJsonPath('id', $site->id)
            ->assertJsonPath('name', 'Alpha Site')
            ->assertJsonPath('url', 'https://alpha.example.test')
            ->assertJsonPath('site_url', 'https://alpha.example.test')
            ->assertJsonPath('home_url', 'https://alpha.example.test/home')
            ->assertJsonPath('wordpress_version', '6.8.2')
            ->assertJsonPath('language_code', 'en_US')
            ->assertJsonPath('connection_status', 'connected')
            ->assertJsonPath('user_name', '');

        $connectionTestAt = $response->json('last_connection_test_at_utc');
        $this->assertIsString($connectionTestAt);
        $this->assertTrue(CarbonImmutable::parse($connectionTestAt)->equalTo(CarbonImmutable::parse('2026-09-06 12:34:56')));

        $payload = $response->json();
        $this->assertArrayNotHasKey('tenant_id', $payload);
        $this->assertArrayNotHasKey('encrypted_secret', $payload);
        $this->assertArrayNotHasKey('application_password', $payload);
        $this->assertArrayNotHasKey('password', $payload);

        $this->assertTrue($updatedAt->equalTo(Site::query()->withoutGlobalScopes()->findOrFail($site->id)->updated_at));
    }

    public function test_get_details_returns_nullable_discovery_fields_and_fails_closed_for_foreign_site(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, [
            'name' => 'Alpha Site',
            'url' => 'https://alpha.example.test',
        ]);
        $betaSite = $this->site($beta, [
            'name' => 'Beta Site',
            'url' => 'https://beta.example.test',
        ]);

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/'.$alphaSite->id)
            ->assertOk()
            ->assertJsonPath('home_url', null)
            ->assertJsonPath('wordpress_version', null)
            ->assertJsonPath('language_code', null)
            ->assertJsonPath('user_name', '');

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/'.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/not-a-number')
            ->assertNotFound();
    }

    public function test_get_details_requires_site_view_permission_in_addition_to_tenant_access(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'limited', ['tenant.view']);
        $site = $this->site($membership, [
            'name' => 'Limited Site',
            'url' => 'https://limited.example.test',
        ]);

        $this->actingAs($user)
            ->getJson('/api/tenants/limited/sites/'.$site->id)
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-details-service-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    /** @param array<string, mixed> $attributes */
    private function site(TenantMembership $membership, array $attributes): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create($attributes);
        $context->forget();

        return $site;
    }
}
