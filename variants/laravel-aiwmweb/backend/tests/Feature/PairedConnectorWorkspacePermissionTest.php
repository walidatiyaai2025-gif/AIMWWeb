<?php

namespace Tests\Feature;

use App\Connector\PairingService;
use App\Models\Connector;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PairedConnectorWorkspacePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successfully_paired_site_advertises_connected_scopes_without_fabricating_health_verification(): void
    {
        [$tenant, $user, $site] = $this->seedMembership('wordpress', ['tenant.view', 'sites.view', 'connector.manage']);

        $context = app(TenantContext::class);
        $context->activate($tenant);
        $token = app(PairingService::class)->create($site);
        app(PairingService::class)->complete($token, (string) Str::uuid(), ['health', 'content.read'], '1');

        $connector = Connector::query()->where('site_id', $site->id)->sole();
        $this->assertNull($connector->verified_at);
        $this->assertSame('paired', Site::query()->findOrFail($site->id)->connection_status);
        $context->forget();

        $this->actingAs($user)->getJson('/tenants/wordpress/context')
            ->assertOk()
            ->assertJsonPath('connectors.0.state', 'connected')
            ->assertJsonPath('connectors.0.scopes.0', 'health');

        $context->activate($tenant);
        $connector->forceFill(['revoked_at' => now()])->save();
        $context->forget();
        $this->actingAs($user)->getJson('/tenants/wordpress/context')
            ->assertOk()
            ->assertJsonPath('connectors.0.state', 'disconnected');
    }

    public function test_bootstrap_owner_permission_backfill_is_tenant_scoped_and_never_grants_other_users(): void
    {
        [$tenant, $owner] = $this->seedMembership('wordpress', ['tenant.view'], 'Owner', true);
        [, $reader] = $this->seedMembership('other', ['tenant.view'], 'Owner', false);
        [$third, $outsider] = $this->seedMembership('foreign', ['tenant.view'], 'Visitor', true);

        $migration = require database_path('migrations/2026_11_05_113000_restore_bootstrap_owner_workspace_permissions.php');
        $migration->up();
        $migration->up(); // Re-running is safe; no duplicate grants.

        foreach (['sync.view', 'backups.view', 'content.view', 'content.edit'] as $name) {
            $this->assertSame(1, DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.tenant_id', $tenant->id)
                ->where('permissions.name', $name)
                ->count());
            $this->assertFalse(DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.tenant_id', [$third->id])
                ->where('permissions.name', $name)
                ->exists());
        }

        $this->actingAs($owner)->get('/tenants/wordpress/module/sync')->assertOk();
        $this->actingAs($reader)->get('/tenants/other/module/sync')->assertForbidden();
        $this->actingAs($outsider)->get('/tenants/foreign/module/sync')->assertForbidden();
    }

    /** @param list<string> $permissions
     *  @return array{Tenant, User, Site}
     */
    private function seedMembership(
        string $slug, array $permissions, string $roleName = 'Reader', bool $platformAdmin = false,
    ): array {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $user->forceFill(['platform_admin' => $platformAdmin])->save();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName]);
        foreach ($permissions as $name) {
            $permission = \App\Models\Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $site = Site::query()->create(['name' => ucfirst($slug), 'url' => "https://{$slug}.example.test"]);
        $context->forget();

        return [$tenant, $user, $site];
    }
}
