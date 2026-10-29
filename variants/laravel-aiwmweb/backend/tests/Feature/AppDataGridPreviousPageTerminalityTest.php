<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AppDataGridPreviousPageTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-598142307A';

    public function test_canonical_ledger_identity_matches_app_data_grid_previous_page_async(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:AppDataGrid', $operation['route_screen']);
        $this->assertSame('@PreviousPageText [PreviousPageAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $operation['verification']);
    }

    public function test_source_semantics_and_laravel_runtime_binding_are_exact(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor'));
        $contract = (string) file_get_contents(resource_path('js/app-data-grid-previous-page-control.ts'));
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="PreviousPageAsync"', $source);
        $this->assertStringContainsString('Disabled="@(CurrentPage <= 1)"', $source);
        $this->assertStringContainsString(
            'private Task PreviousPageAsync(MouseEventArgs _) { if (CurrentPage > 1) CurrentPage--; return Task.CompletedTask; }',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $contract);
        $this->assertStringContainsString(
            'return currentPage > 1 ? currentPage - 1 : currentPage;',
            $contract,
        );
        $this->assertStringContainsString('onPage(previousAppDataGridPage(page))', $components);
        $this->assertStringContainsString('disabled={page <= 1}', $components);
        $this->assertStringContainsString(
            "route.key === 'email-history' ? APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID : undefined",
            $pages,
        );
    }

    public function test_real_email_history_consumer_is_read_only_and_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $before = EmailDelivery::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?page=1')
            ->assertOk()
            ->assertJsonPath('current_page', 1);

        $this->assertSame($before, EmailDelivery::withoutGlobalScopes()->count());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/beta/email/deliveries?page=1')
            ->assertNotFound();

        $this->assertSame($before, EmailDelivery::withoutGlobalScopes()->count());
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "app-data-grid-previous-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
