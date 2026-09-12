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

class ExecutionConnectSiteControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-148B15F121';

    public function test_exact_canonical_operation_metadata_matches_execution_center_connect_site_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/execution-center | /module/execution', $operation['route_screen']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ExecutionCenter.razor',
            $operation['current_source'],
        );
        $this->assertSame('@(L.IsArabic ? -> /sites/connect', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_authoritative_sites_read_is_tenant_bound_and_foreign_tenant_is_404(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', [
            'tenant.view',
            'execution.view',
            'sites.view',
            'sites.manage',
        ]);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', [
            'tenant.view',
            'execution.view',
            'sites.view',
            'sites.manage',
        ]);

        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk();

        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/beta/sites')
            ->assertNotFound();
    }

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
            'name' => "execution-connect-site-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
