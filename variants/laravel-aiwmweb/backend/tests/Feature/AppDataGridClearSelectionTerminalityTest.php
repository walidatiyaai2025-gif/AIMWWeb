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

final class AppDataGridClearSelectionTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-2E94028E4E';

    public function test_canonical_identity_and_laravel_binding_match_clear_selection_async(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:AppDataGrid', $operation['route_screen']);
        $this->assertSame('@ClearSelectionText [ClearSelectionAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);

        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor'),
        );
        $shared = (string) file_get_contents(resource_path('js/app-data-grid-clear-selection-control.tsx'));
        $consumer = (string) file_get_contents(resource_path('js/sites-bulk-delete-control.tsx'));

        $this->assertStringContainsString('OnClick="ClearSelectionAsync"', $source);
        $this->assertStringContainsString('_selectedKeys.Clear();', $source);
        $this->assertStringContainsString('await NotifySelectionChangedAsync();', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $shared);
        $this->assertStringContainsString('clearLocalSelection();', $shared);
        $this->assertStringContainsString('await onSelectionChanged?.([]);', $shared);
        $this->assertStringContainsString('AppDataGridClearSelectionControl', $consumer);
        $this->assertStringContainsString('onClearSelection={() => setSelected(new Set())}', $consumer);
    }

    public function test_real_consumer_is_tenant_scoped_and_foreign_tenant_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha-clear-selection', ['tenant.view', 'sites.view', 'sites.manage']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign-clear-selection']);

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha-clear-selection/sites')
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/tenants/foreign-clear-selection/sites')
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
        $role = Role::query()->create(['name' => "grid-clear-selection-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
