<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantContextSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_site_selector_fails_closed_across_auth_permission_tenant_and_direct_id_boundaries(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $noPermissionUser = User::factory()->create();
        $noPermissionMembership = $this->membership($noPermissionUser, 'gamma', []);
        $noPermissionSite = $this->site($noPermissionMembership, 'Gamma Site');

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $this->actingAs($noPermissionUser)
            ->getJson('/tenants/gamma/context?site='.$noPermissionSite->id)
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site=999999999')
            ->assertNotFound();
    }

    public function test_context_site_selector_validates_malformed_ids_fail_closed(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view']);
        $this->site($membership, 'Alpha Site');

        foreach (['0', '-1', 'abc', '1.5'] as $siteId) {
            $this->actingAs($user)
                ->getJson('/tenants/alpha/context?site='.urlencode($siteId))
                ->assertNotFound();
        }
    }

    public function test_owned_site_context_is_authoritative_read_only_idempotent_and_does_not_expose_connector_secret(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view']);
        $site = $this->site($membership, 'Alpha Site');
        $secret = 'tenant-context-secret-sentinel';
        $this->connector($membership, $site, $secret);

        $siteBefore = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->updated_at?->toISOString();
        $countsBefore = [
            'sites' => Site::query()->withoutGlobalScopes()->count(),
            'connectors' => Connector::query()->withoutGlobalScopes()->count(),
            'memberships' => TenantMembership::query()->withoutGlobalScopes()->count(),
        ];

        $first = $this->actingAs($user)->getJson('/tenants/alpha/context?site='.$site->id);
        $first->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('active_site.name', 'Alpha Site')
            ->assertJsonPath('active_site.status', 'active');
        $this->assertStringNotContainsString($secret, $first->getContent());
        $this->assertArrayNotHasKey('encrypted_secret', $first->json('connectors.0') ?? []);

        $second = $this->actingAs($user)->getJson('/tenants/alpha/context?site='.$site->id);
        $second->assertOk()
            ->assertJsonPath('tenant.id', $membership->tenant_id)
            ->assertJsonPath('active_site.id', $site->id);
        $this->assertStringNotContainsString($secret, $second->getContent());

        $this->assertSame($countsBefore['sites'], Site::query()->withoutGlobalScopes()->count());
        $this->assertSame($countsBefore['connectors'], Connector::query()->withoutGlobalScopes()->count());
        $this->assertSame($countsBefore['memberships'], TenantMembership::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            $siteBefore,
            Site::query()->withoutGlobalScopes()->findOrFail($site->id)->updated_at?->toISOString(),
        );
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "tenant-context-security-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('tenant', $tenant);
        $context->forget();

        return $membership;
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function connector(TenantMembership $membership, Site $site, string $secret): Connector
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $connector = Connector::query()->create([
            'site_id' => $site->id,
            'identity' => (string) Str::uuid(),
            'encrypted_secret' => $secret,
            'protocol_version' => '1',
            'capabilities' => [],
            'enabled_scopes' => [],
            'verified_at' => now(),
        ]);
        $context->forget();

        return $connector;
    }
}
