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

final class AppDataGridSelectAllFilteredTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-3BFC8AF183';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_canonical_ledger_identity_matches_app_data_grid_select_all_filtered_async(): void
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
        $this->assertSame('@SelectAllFilteredText [SelectAllFilteredAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $operation['verification']);
    }

    public function test_source_semantics_and_laravel_runtime_binding_are_exact(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor'));
        $contract = (string) file_get_contents(resource_path('js/app-data-grid-select-all-filtered-control.ts'));
        $consumer = (string) file_get_contents(resource_path('js/seo-visible-controls.tsx'));

        $this->assertStringContainsString('Text="@SelectAllFilteredText"', $source);
        $this->assertStringContainsString('OnClick="SelectAllFilteredAsync"', $source);
        $this->assertStringContainsString('FilteredItems.Count > PagedItems.Count', $source);
        $this->assertStringContainsString(
            'foreach (var item in FilteredItems) _selectedKeys.Add(GetKey(item));',
            $source,
        );
        $this->assertStringContainsString('await NotifySelectionChangedAsync();', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $contract);
        $this->assertStringContainsString('for (const key of filteredKeys) next.add(key);', $contract);
        $this->assertStringContainsString('return filteredCount > pagedCount;', $contract);
        $this->assertStringContainsString('APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID', $consumer);
        $this->assertStringContainsString('selectAllAppDataGridFiltered(current, selectableFilteredIds)', $consumer);
        $this->assertStringContainsString('selectableFilteredIds.length', $consumer);
        $this->assertStringContainsString('selectableVisibleIds.length', $consumer);
    }

    public function test_real_seo_consumer_is_read_only_and_foreign_tenant_site_fails_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'seo.view']);
        $alphaSite = $this->site($alpha, 'Alpha SEO', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta SEO', 'https://beta.test');

        $beforeSites = Site::withoutGlobalScopes()->count();
        $beforeMemberships = TenantMembership::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/seo')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/seo')
            ->assertNotFound();

        $this->assertSame($beforeSites, Site::withoutGlobalScopes()->count());
        $this->assertSame($beforeMemberships, TenantMembership::withoutGlobalScopes()->count());
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "app-data-grid-select-all-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => $url, 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
