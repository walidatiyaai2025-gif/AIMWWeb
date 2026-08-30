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

class SitesRequestBulkDeleteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-7C3B0E834E';

    public function test_canonical_operation_is_the_critical_sites_bulk_delete_request_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('critical', $operation['risk']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertStringContainsString('RequestBulkDeleteAsync', (string) $operation['visible_control']);
    }

    public function test_request_control_only_opens_the_existing_confirmation_boundary(): void
    {
        $frontend = file_get_contents(resource_path('js/sites-bulk-delete-control.tsx'));

        $this->assertStringContainsString("const REQUEST_OPERATION_ID = '".self::OPERATION_ID."';", $frontend);
        $this->assertStringContainsString('data-canonical-operation={REQUEST_OPERATION_ID}', $frontend);
        $this->assertStringContainsString('const requestBulkDelete = () => setConfirmOpen(true);', $frontend);
        $this->assertStringContainsString('onClick={requestBulkDelete}', $frontend);
        $this->assertStringContainsString('role="dialog"', $frontend);
        $this->assertStringContainsString('data-canonical-operation={OPERATION_ID}', $frontend);
        $this->assertStringContainsString('onClick={() => mutation.mutate(selectedIds)}', $frontend);
    }

    public function test_destructive_boundary_remains_permission_and_tenant_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);

        $this->actingAs($limited)
            ->deleteJson('/api/tenants/limited/sites', ['ids' => [1]])
            ->assertForbidden();

        $authorized = User::factory()->create();
        $alpha = $this->membership($authorized, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $foreign = $this->site($beta, 'Foreign Site');

        $this->actingAs($authorized)
            ->deleteJson('/api/tenants/alpha/sites', ['ids' => [$foreign->id]])
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $foreign->id, 'tenant_id' => $beta->id]);
        $this->assertNotSame($alpha->tenant_id, $beta->id);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "sites-request-bulk-{$slug}-{$user->id}"]);

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
            'url' => 'https://'.str($name)->slug().'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
