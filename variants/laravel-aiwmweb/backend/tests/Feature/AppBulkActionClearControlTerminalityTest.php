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

final class AppBulkActionClearControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-CDE0C219B5';

    public function test_canonical_operation_is_the_shared_bulk_action_clear_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('low', $operation['risk']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('component:AppBulkActionBar', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Shared/AppBulkActionBar.razor', $operation['current_source']);
        $this->assertStringContainsString('@ClearText', (string) $operation['visible_control']);
        $this->assertStringContainsString('ClearAsync', (string) $operation['visible_control']);
    }

    public function test_production_control_is_wired_to_a_real_tenant_scoped_sites_consumer(): void
    {
        $control = (string) file_get_contents(resource_path('js/app-bulk-action-clear-control.tsx'));
        $consumer = (string) file_get_contents(resource_path('js/sites-bulk-delete-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-source-handler="ClearAsync"', $control);
        $this->assertStringContainsString('if (selectedCount <= 0 || !onClear) return null;', $control);
        $this->assertStringContainsString('disabled={busy}', $control);
        $this->assertStringContainsString('if (!busy) void onClear();', $control);

        $this->assertStringContainsString("import { AppBulkActionClearControl } from './app-bulk-action-clear-control';", $consumer);
        $this->assertStringContainsString('<AppBulkActionClearControl', $consumer);
        $this->assertStringContainsString('selectedCount={selectedIds.length}', $consumer);
        $this->assertStringContainsString('busy={mutation.isPending}', $consumer);
        $this->assertStringContainsString("context.permissions.includes('sites.manage')", $consumer);
        $this->assertStringContainsString('onClear={() => setSelected(new Set())}', $consumer);
    }

    public function test_sites_consumer_fails_closed_for_foreign_tenant(): void
    {
        $actor = User::factory()->create();
        $this->membership($actor, 'alpha-bulk-clear', ['tenant.view', 'sites.view', 'sites.manage']);
        Tenant::query()->create(['name' => 'Beta Bulk Clear', 'slug' => 'beta-bulk-clear']);

        $this->actingAs($actor)
            ->get('/tenants/beta-bulk-clear/sites')
            ->assertNotFound();
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
            'name' => "bulk-clear-{$slug}-{$user->id}",
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
