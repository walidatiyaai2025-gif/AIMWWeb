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

class SitesShowAllVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-C178278FCB';

    public function test_canonical_operation_is_the_terminal_sites_show_all_visible_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertStringContainsString('ShowAllSites', (string) $operation['visible_control']);

        $frontend = file_get_contents(resource_path('js/pages.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("setSitesFilter('all')", $frontend);
    }

    public function test_sites_view_member_can_reach_show_all_surface_without_manage_permission(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk();
    }

    public function test_missing_sites_view_is_forbidden_and_foreign_tenant_fails_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/sites')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'sites.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($authorized)
            ->get('/tenants/beta/sites')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "sites-show-all-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
