<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurrentUserLogsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-CD4ADA5087';

    public function test_guest_cannot_read_tenant_logs(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $this->getJson("/tenants/{$tenant->slug}/admin/logs")->assertUnauthorized();
    }

    public function test_diagnostics_only_member_fails_closed_at_the_actual_logs_endpoint(): void
    {
        [, $membership] = $this->tenantMember('alpha', ['tenant.view', 'diagnostics.view']);

        $this->actingAs($membership->user)
            ->getJson('/tenants/alpha/admin/logs')
            ->assertForbidden();
    }

    public function test_foreign_tenant_route_is_rejected_before_logs_are_read(): void
    {
        $this->assertSame('AIMW-IDEN-CD4ADA5087', self::OPERATION_ID);
        [, $alpha] = $this->tenantMember('alpha', ['tenant.view', 'diagnostics.view', 'operations.manage']);
        [$beta] = $this->tenantMember('beta', ['tenant.view', 'diagnostics.view', 'operations.manage']);
        DB::table('operation_logs')->insert([
            'tenant_id' => $beta->id,
            'operation_execution_id' => null,
            'correlation_id' => 'beta-only',
            'level' => 'error',
            'message' => 'beta-secret-diagnostic',
            'context' => json_encode([]),
            'occurred_at' => now(),
        ]);

        $this->actingAs($alpha->user)
            ->getJson('/tenants/beta/admin/logs')
            ->assertNotFound();
    }

    public function test_authorized_member_reads_only_active_tenant_logs_and_never_foreign_rows(): void
    {
        [$alphaTenant, $membership] = $this->tenantMember('alpha', ['tenant.view', 'diagnostics.view', 'operations.manage']);
        [$betaTenant] = $this->tenantMember('beta', ['tenant.view', 'diagnostics.view', 'operations.manage']);
        DB::table('operation_logs')->insert([
            [
                'tenant_id' => $alphaTenant->id,
                'operation_execution_id' => null,
                'correlation_id' => 'alpha-log',
                'level' => 'info',
                'message' => 'alpha-visible',
                'context' => json_encode([]),
                'occurred_at' => now(),
            ],
            [
                'tenant_id' => $betaTenant->id,
                'operation_execution_id' => null,
                'correlation_id' => 'beta-log',
                'level' => 'error',
                'message' => 'beta-hidden',
                'context' => json_encode([]),
                'occurred_at' => now(),
            ],
        ]);

        $response = $this->actingAs($membership->user)
            ->getJson('/tenants/alpha/admin/logs')
            ->assertOk();

        $response->assertJsonFragment(['message' => 'alpha-visible']);
        $response->assertJsonMissing(['message' => 'beta-hidden']);
    }

    private function tenantMember(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'logs-'.$slug.'-'.bin2hex(random_bytes(3))]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }
}
