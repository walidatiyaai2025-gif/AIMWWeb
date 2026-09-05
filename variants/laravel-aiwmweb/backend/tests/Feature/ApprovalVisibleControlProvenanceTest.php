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

class ApprovalVisibleControlProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private const LOAD_OPERATION_ID = 'AIMW-APPR-31A36E339F';

    private const EXECUTION_LINK_OPERATION_ID = 'AIMW-APPR-B360D1C8BA';

    public function test_exact_production_markers_are_bound_and_foreign_tenant_paths_fail_closed(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', [
            'tenant.view',
            'approvals.view',
            'operations.manage',
            'execution.view',
        ]);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $app = file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString('data-canonical-operation="'.self::LOAD_OPERATION_ID.'"', $app);
        $this->assertStringContainsString('data-canonical-operation="'.self::EXECUTION_LINK_OPERATION_ID.'"', $app);

        $this->actingAs($user)
            ->getJson('/api/tenants/beta/approvals')
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/tenants/beta/module/execution')
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
        $role = Role::query()->create(['name' => "approval-provenance-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
