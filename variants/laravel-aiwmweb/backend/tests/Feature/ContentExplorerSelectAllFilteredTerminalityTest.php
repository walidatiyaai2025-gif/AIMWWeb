<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentExplorerSelectAllFilteredTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-AC737C80BF';

    public function test_source_contract_selects_every_filtered_item_only_for_editors(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));

        $this->assertStringContainsString('@onclick="SelectAllFiltered"', $source);
        $this->assertStringContainsString('private void SelectAllFiltered()', $source);
        $this->assertStringContainsString('if (!CanEditContent) return;', $source);
        $this->assertStringContainsString('foreach (var item in _filteredContent) _selected.Add(ContentKey(item));', $source);
    }

    public function test_laravel_runtime_binds_select_all_to_authoritative_filtered_explorer_reads(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-explorer-select-all-filtered-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("url.searchParams.set('per_page', '100')", $control);
        $this->assertStringContainsString("url.searchParams.set('search', search.trim())", $control);
        $this->assertStringContainsString('loadAllFilteredExplorerKeys', $pages);
        $this->assertStringContainsString("context.permissions.includes('content.edit')", $pages);
        $this->assertStringContainsString('ContentExplorerSelectAllFilteredControl', $pages);

        $this->assertStringNotContainsString("method: 'POST'", $control);
        $this->assertStringNotContainsString('mutate', strtolower($control));
        $this->assertStringNotContainsString('AIMW-CONT-', str_replace(self::OPERATION_ID, '', $control));
    }

    public function test_authoritative_filtered_reads_cover_all_pages_and_fail_closed_cross_tenant(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'select-alpha', ['tenant.view', 'sites.view', 'content.edit']);
        $site = $this->site($membership, 'Select Alpha');

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        for ($index = 1; $index <= 105; $index++) {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 2000 + $index,
                'type' => $index % 2 === 0 ? 'page' : 'post',
                'status' => 'publish',
                'title' => 'Needle '.$index,
                'slug' => 'needle-'.$index,
                'synced_at' => now(),
            ]);
        }
        ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => 9999,
            'type' => 'post',
            'status' => 'publish',
            'title' => 'Other row',
            'slug' => 'other-row',
            'synced_at' => now(),
        ]);
        $context->forget();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/select-alpha/sites/'.$site->id.'/explorer?search=Needle&per_page=100&page=1')
            ->assertOk()
            ->assertJsonPath('total', 105)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(100, 'data');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/select-alpha/sites/'.$site->id.'/explorer?search=Needle&per_page=100&page=2')
            ->assertOk()
            ->assertJsonPath('total', 105)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(5, 'data');

        $foreignOwner = User::factory()->create();
        $foreignMembership = $this->membership($foreignOwner, 'select-foreign', ['tenant.view', 'sites.view', 'content.edit']);
        $foreignSite = $this->site($foreignMembership, 'Select Foreign');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/select-alpha/sites/'.$foreignSite->id.'/explorer?search=Needle')
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/select-foreign/sites/'.$foreignSite->id.'/explorer?search=Needle')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "select-all-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
        } finally {
            $context->forget();
        }
    }
}
