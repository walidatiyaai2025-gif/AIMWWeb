<?php

namespace Tests\Feature;

use App\Models\ConnectorPairing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExistingSitePairingTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_owned_site_issues_short_lived_token_without_creating_duplicate_site(): void
    {
        [$tenant, $user, $site] = $this->seedSite('alpha', ['tenant.view', 'sites.view', 'connector.manage']);

        $first = $this->actingAs($user)
            ->postJson("/api/tenants/alpha/sites/{$site->id}/pairing")
            ->assertOk()
            ->assertJsonPath('expires_in', 600);

        $token = (string) $first->json('pairing_token');
        $this->assertSame(64, strlen($token));
        $this->assertSame(1, Site::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $pairing = ConnectorPairing::withoutGlobalScopes()->where('site_id', $site->id)->sole();
        $this->assertSame(hash('sha256', $token), $pairing->token_hash);
        $this->assertNotSame($token, $pairing->token_hash);
        $this->assertNull($pairing->used_at);
        $this->assertTrue($pairing->expires_at->isFuture());

        $second = $this->actingAs($user)
            ->postJson("/api/tenants/alpha/sites/{$site->id}/pairing")
            ->assertOk();

        $newToken = (string) $second->json('pairing_token');
        $this->assertNotSame($token, $newToken);
        $this->assertSame(1, ConnectorPairing::withoutGlobalScopes()->where('site_id', $site->id)->count());
        $this->assertSame(hash('sha256', $newToken),
            ConnectorPairing::withoutGlobalScopes()->where('site_id', $site->id)->sole()->token_hash);
        $this->assertSame(1, Site::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_connector_manage_permission_is_required(): void
    {
        [, $user, $site] = $this->seedSite('read-only', ['tenant.view', 'sites.view']);

        $this->actingAs($user)
            ->postJson("/api/tenants/read-only/sites/{$site->id}/pairing")
            ->assertForbidden();

        $this->assertSame(0, ConnectorPairing::withoutGlobalScopes()->count());
    }

    public function test_foreign_tenant_and_foreign_site_cannot_issue_a_token(): void
    {
        [, $user] = $this->seedSite('alpha', ['tenant.view', 'sites.view', 'connector.manage']);
        [, , $foreignSite] = $this->seedSite('beta', ['tenant.view', 'sites.view', 'connector.manage']);

        $this->actingAs($user)
            ->postJson("/api/tenants/alpha/sites/{$foreignSite->id}/pairing")
            ->assertNotFound();
        $this->actingAs($user)
            ->postJson("/api/tenants/beta/sites/{$foreignSite->id}/pairing")
            ->assertNotFound();

        $this->assertSame(0, ConnectorPairing::withoutGlobalScopes()->count());
    }

    /**
     * @param  list<string>  $permissions
     * @return array{Tenant, User, Site}
     */
    private function seedSite(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();

        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "existing-pair-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $site = Site::query()->create(['name' => 'WordPress', 'url' => 'https://example.test']);
        $context->forget();

        return [$tenant, $user, $site];
    }
}
