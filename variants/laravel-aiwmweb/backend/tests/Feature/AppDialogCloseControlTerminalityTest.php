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

final class AppDialogCloseControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-ABE31C5C4E';

    public function test_dialog_consumer_site_reads_remain_tenant_scoped_and_permission_guarded(): void
    {
        $actor = User::factory()->create();
        $alpha = $this->membership($actor, 'alpha-dialog', ['tenant.view', 'sites.view']);
        $own = $this->site($alpha->tenant, 'Own dialog site');
        $this->actingAs($actor)->getJson('/api/tenants/alpha-dialog/sites/'.$own->id)->assertOk();
        $other = User::factory()->create();
        $beta = $this->membership($other, 'beta-dialog', ['tenant.view', 'sites.view']);
        $foreign = $this->site($beta->tenant, 'Foreign dialog site');
        $this->actingAs($actor)->getJson('/api/tenants/alpha-dialog/sites/'.$foreign->id)->assertNotFound();
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited-dialog', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership->tenant, 'Limited dialog site');
        $this->actingAs($limited)->getJson('/api/tenants/limited-dialog/sites/'.$limitedSite->id)->assertForbidden();
        $this->assertNotSame($alpha->tenant_id, $beta->tenant_id);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "filterbar-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://foreign-filterbar.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
