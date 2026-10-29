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

final class GlobalPagesResetTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-FD27B795ED';

    public function test_source_reset_is_wired_to_a_real_authorized_tenant_read(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPagesExplorer.razor'));
        $widget = (string) file_get_contents(resource_path('js/global-pages-confirm-trash-widget.tsx'));
        $control = (string) file_get_contents(resource_path('js/global-pages-reset-control.tsx'));

        $this->assertStringContainsString('@onclick="ResetAsync"', $source);
        $this->assertStringContainsString('private async Task ResetAsync()', $source);
        $this->assertStringContainsString('_siteFilter = string.Empty;', $source);
        $this->assertStringContainsString('_statusFilter = "all";', $source);
        $this->assertStringContainsString('_pageSize = 25;', $source);
        $this->assertStringContainsString('_page = 1; await LoadAsync();', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('GlobalPagesResetControl', $widget);
        $this->assertStringContainsString('setFilterRevision((value) => value + 1)', $widget);
        $this->assertStringContainsString('setSelected(new Set())', $widget);
    }

    public function test_reset_reads_unfiltered_synchronized_pages_and_enforces_tenant_rbac_and_direct_site_isolation(): void
    {
        $this->getJson('/api/v1/tenants/alpha-pages-reset/global-pages?page=1&per_page=25')
            ->assertUnauthorized();

        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Alpha pages reset', 'slug' => 'alpha-pages-reset']);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'pages-reset-viewer']);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $site = Site::query()->create([
            'name' => 'Alpha pages', 'url' => 'https://alpha-pages.example.test',
            'status' => 'active', 'connection_status' => 'connected',
        ]);
        foreach ([['id' => 11, 'title' => 'Needle page', 'status' => 'draft'],
                  ['id' => 12, 'title' => 'Other page', 'status' => 'publish']] as $row) {
            ContentItem::query()->create([
                'site_id' => $site->id, 'remote_id' => $row['id'], 'type' => 'page',
                'title' => $row['title'], 'slug' => 'page-'.$row['id'],
                'body' => '', 'excerpt' => '', 'status' => $row['status'],
                'remote_modified_at' => now(),
            ]);
        }
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-reset/global-pages?page=1&per_page=25')
            ->assertForbidden();

        app(TenantContext::class)->activate($tenant);
        $permission = Permission::query()->firstOrCreate(['name' => 'content.view']);
        $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-reset/global-pages?search=Needle&status=draft&per_page=10&page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 1);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-reset/global-pages?page=1&per_page=25')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2)->assertJsonPath('current_page', 1);

        $foreignTenant = Tenant::query()->create(['name' => 'Beta pages', 'slug' => 'beta-pages-reset']);
        app(TenantContext::class)->activate($foreignTenant);
        $foreignSite = Site::query()->create([
            'name' => 'Beta pages', 'url' => 'https://beta-pages.example.test',
            'status' => 'active', 'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/beta-pages-reset/global-pages?page=1&per_page=25')
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-reset/global-pages?site_id='.$foreignSite->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-reset/global-pages?per_page=1000')
            ->assertUnprocessable();
    }
}
