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

final class ContentExplorerApplyFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-419C403EFB';

    public function test_source_and_laravel_runtime_bind_exact_apply_filters_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $frontend = (string) file_get_contents(
            resource_path('js/content-explorer-apply-filters-control.tsx'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/ContentExplorerReadController.php'),
        );

        $this->assertStringContainsString('@onclick="ApplyFiltersAsync"', $source);
        $this->assertStringContainsString('private async Task ApplyFiltersAsync()', $source);
        $this->assertStringContainsString('await ReloadDataAsync(true)', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-canonical-operation', $frontend);
        $this->assertStringContainsString('context.api.explorer', $frontend);
        $this->assertStringContainsString("params.set('type', filters.type)", $frontend);
        $this->assertStringContainsString("params.set('status', filters.status)", $frontend);
        $this->assertStringContainsString("params.set('per_page', String(filters.perPage))", $frontend);

        $this->assertStringContainsString("\$auth->authorize('sites.view')", $controller);
        $this->assertStringContainsString("\$request->query('search', '')", $controller);
        $this->assertStringContainsString("\$request->query('status', '')", $controller);
        $this->assertStringContainsString("\$request->query('type', 'all')", $controller);
        $this->assertStringContainsString("\$request->integer('per_page', 20)", $controller);
    }

    public function test_authoritative_filters_work_and_foreign_tenant_site_is_a_404(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership(
            $alphaUser,
            'alpha-filter',
            ['tenant.view', 'sites.view'],
        );
        $alphaSite = $this->site($alphaMembership->tenant, 'Alpha Filter Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership(
            $betaUser,
            'beta-filter',
            ['tenant.view', 'sites.view'],
        );
        $betaSite = $this->site($betaMembership->tenant, 'Beta Filter Site');

        $context = app(TenantContext::class);
        $context->activate($alphaMembership->tenant);
        ContentItem::query()->create([
            'site_id' => $alphaSite->id,
            'remote_id' => 101,
            'type' => 'post',
            'title' => 'Needle match',
            'slug' => 'needle-match',
            'status' => 'publish',
            'remote_modified_at' => '2026-10-27 12:00:00',
        ]);
        ContentItem::query()->create([
            'site_id' => $alphaSite->id,
            'remote_id' => 102,
            'type' => 'page',
            'title' => 'Needle page',
            'slug' => 'needle-page',
            'status' => 'publish',
            'remote_modified_at' => '2026-10-27 11:00:00',
        ]);
        ContentItem::query()->create([
            'site_id' => $alphaSite->id,
            'remote_id' => 103,
            'type' => 'post',
            'title' => 'Other draft',
            'slug' => 'other-draft',
            'status' => 'draft',
            'remote_modified_at' => '2026-10-27 10:00:00',
        ]);
        $context->forget();

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-filter/sites/'.$alphaSite->id.'/explorer?search=Needle&type=post&status=publish&per_page=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 101)
            ->assertJsonPath('data.0.type', 'post')
            ->assertJsonPath('data.0.status', 'publish');

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-filter/sites/'.$betaSite->id.'/explorer?search=Needle&type=post&status=publish&per_page=25')
            ->assertNotFound();

        $this->assertNotSame($alphaMembership->tenant_id, $betaMembership->tenant_id);
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
        $role = Role::query()->create([
            'name' => 'content-explorer-filter-'.$slug.'-'.$user->id,
        ]);

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
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
