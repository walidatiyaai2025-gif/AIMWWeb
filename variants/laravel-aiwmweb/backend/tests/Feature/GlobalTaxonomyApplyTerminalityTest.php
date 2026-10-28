<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TaxonomyTerm;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalTaxonomyApplyTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-B955324F04';

    public function test_source_apply_is_bound_to_authorized_filters_and_authoritative_page_one_get(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalTaxonomyExplorer.razor'));
        $control = (string) file_get_contents(resource_path('js/global-taxonomy-apply-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString('@onclick="ApplyAsync"', $source);
        $this->assertStringContainsString('private async Task ApplyAsync(){_page=1;await LoadAsync();}', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation', $control);
        $this->assertStringContainsString('onApplyRequested={applyGlobalTaxonomyFilters}', $pages);
        $this->assertStringContainsString('setPage(1)', $pages);
        $this->assertStringContainsString('globalTaxonomyApplyQuery(nextFilters)', $pages);
        $this->assertStringContainsString("where('usage_count', '>=', \$minimumUsage)", $controller);
        $this->assertStringContainsString('Site::query()->findOrFail($site)', $controller);
    }

    public function test_real_search_type_usage_and_page_size_filters_preserve_tenant_site(): void
    {
        $alice = User::factory()->create();
        $alpha = $this->membership($alice, 'taxonomy-apply-alpha', ['tenant.view', 'sites.view', 'content.view']);
        $site = $this->site($alpha->tenant, 'Alpha WP');
        $this->term($alpha->tenant, $site, 111, 'category', 'News', 'news', 10);
        $this->term($alpha->tenant, $site, 112, 'category', 'News Low', 'news-low', 1);
        $this->term($alpha->tenant, $site, 113, 'post_tag', 'News Tag', 'news-tag', 20);
        $this->term($alpha->tenant, $site, 114, 'category', 'Other', 'other', 50);

        $bob = User::factory()->create();
        $beta = $this->membership($bob, 'taxonomy-apply-beta', ['tenant.view', 'sites.view', 'content.view']);
        $foreignSite = $this->site($beta->tenant, 'Beta WP');
        $this->term($beta->tenant, $foreignSite, 999, 'category', 'Secret', 'secret', 50);

        $url = '/api/v1/tenants/taxonomy-apply-alpha/sites/'.$site->id
            .'/taxonomy?taxonomy=category&search=news&minimum_usage=5&per_page=10&page=1';
        $response = $this->actingAs($alice)->getJson($url)->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'News')
            ->assertJsonCount(1, 'data');
        $this->assertSame(10, $response->json('per_page'));

        $this->actingAs($alice)->getJson('/api/v1/tenants/taxonomy-apply-alpha/sites/'.$site->id.'/taxonomy?per_page=25')
            ->assertOk()->assertJsonPath('total', 4);
        $this->actingAs($alice)->getJson('/api/v1/tenants/taxonomy-apply-alpha/sites/'.$foreignSite->id.'/taxonomy')
            ->assertNotFound();
        $this->actingAs($alice)->getJson('/api/v1/tenants/taxonomy-apply-beta/sites/'.$foreignSite->id.'/taxonomy')
            ->assertNotFound();
    }

    public function test_missing_permissions_fail_with_403_and_guest_with_401(): void
    {
        $limited = User::factory()->create();
        $membership = $this->membership($limited, 'taxonomy-apply-limited', ['tenant.view', 'sites.view']);
        $site = $this->site($membership->tenant, 'Limited WP');
        $url = '/api/v1/tenants/taxonomy-apply-limited/sites/'.$site->id.'/taxonomy?minimum_usage=5';
        $this->actingAs($limited)->getJson($url)->assertForbidden(); // 403
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
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
        try {
            return Site::query()->create(['name' => $name, 'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test', 'status' => 'active']);
        } finally {
            $context->forget();
        }
    }

    private function term(Tenant $tenant, Site $site, int $remoteId, string $taxonomy, string $name, string $slug, int $usage): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        try {
            TaxonomyTerm::query()->create([
                'site_id' => $site->id,
                'remote_id' => $remoteId,
                'taxonomy' => $taxonomy,
                'name' => $name,
                'slug' => $slug,
                'usage_count' => $usage,
            ]);
        } finally {
            $context->forget();
        }
    }
}
