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

final class ContentExplorerResetFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-627C90DC5C';

    public function test_source_reset_is_wired_to_real_laravel_explorer_read(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/content-explorer-reset-filters-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/ContentExplorerReadController.php'),
        );

        $this->assertStringContainsString('@onclick="ResetFiltersAsync"', $source);
        $this->assertStringContainsString('private async Task ResetFiltersAsync()', $source);
        $this->assertStringContainsString('_query = string.Empty;', $source);
        $this->assertStringContainsString('_type = "all";', $source);
        $this->assertStringContainsString('_status = "all";', $source);
        $this->assertStringContainsString('await ReloadDataAsync(true);', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation', $control);
        $this->assertStringContainsString('context.api.explorer', $control);
        $this->assertStringContainsString('onResetRequested={resetContentExplorerFilters}', $pages);
        $this->assertStringContainsString("setSearchInput('')", $pages);
        $this->assertStringContainsString("setSearch('')", $pages);
        $this->assertStringContainsString('setPage(1)', $pages);
        $this->assertStringContainsString('setExplorerSelectedKeys(new Set())', $pages);
        $this->assertStringContainsString('queryClient.fetchQuery({', $pages);
        $this->assertStringContainsString("endpointWithQuery(trustedEndpoint, 1, '')", $pages);
        $this->assertStringContainsString("\$auth->authorize('sites.view')", $controller);
        $this->assertStringContainsString("Site::query()->findOrFail(\$site)", $controller);
    }

    public function test_reset_defaults_reread_unfiltered_records_and_never_cross_a_tenant(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'reset-alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha->tenant, 'Reset Alpha');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'reset-beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta->tenant, 'Reset Beta');

        $tenantContext = app(TenantContext::class);
        $tenantContext->activate($alpha->tenant);
        foreach ([
            ['remote_id' => 301, 'type' => 'post', 'title' => 'Needle post', 'slug' => 'needle-post', 'status' => 'publish'],
            ['remote_id' => 302, 'type' => 'page', 'title' => 'Other page', 'slug' => 'other-page', 'status' => 'draft'],
        ] as $record) {
            ContentItem::query()->create([
                'site_id' => $alphaSite->id,
                ...$record,
                'remote_modified_at' => '2026-10-27 12:00:00',
            ]);
        }
        $tenantContext->forget();

        $filtered = '/api/v1/tenants/reset-alpha/sites/'.$alphaSite->id
            .'/explorer?page=1&search=Needle&type=post&status=publish';
        $defaults = '/api/v1/tenants/reset-alpha/sites/'.$alphaSite->id.'/explorer?page=1';

        $this->actingAs($alphaUser)
            ->getJson($filtered)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($alphaUser)
            ->getJson($defaults)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/reset-alpha/sites/'.$betaSite->id.'/explorer?page=1')
            ->assertNotFound();

        $this->assertNotSame($alpha->tenant_id, $beta->tenant_id);
    }

    public function test_reset_read_requires_authorized_tenant_site_permissions(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'reset-limited', ['tenant.view']);
        $site = $this->site($member->tenant, 'Reset Limited');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/reset-limited/sites/'.$site->id.'/explorer?page=1')
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $tenantContext = app(TenantContext::class);
        $tenantContext->activate($tenant);
        $member = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'explorer-reset-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $member->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $tenantContext->forget();
        return $member->fresh('tenant');
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $tenantContext = app(TenantContext::class);
        $tenantContext->activate($tenant);
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
                'status' => 'active',
            ]);
        } finally {
            $tenantContext->forget();
        }
    }
}
