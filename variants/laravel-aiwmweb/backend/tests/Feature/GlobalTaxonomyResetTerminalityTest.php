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

final class GlobalTaxonomyResetTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-5EE3F98A28';

    public function test_source_reset_and_real_tenant_bound_frontend_read_are_wired(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalTaxonomyExplorer.razor'));
        $control = (string) file_get_contents(resource_path('js/global-taxonomy-reset-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString('@onclick="ResetAsync"', $source);
        $this->assertStringContainsString('private async Task ResetAsync()', $source);
        $this->assertStringContainsString('_query=string.Empty;', $source);
        $this->assertStringContainsString('_typeFilter="all";', $source);
        $this->assertStringContainsString('_page=1;await LoadAsync();', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation', $control);
        $this->assertStringContainsString('context.api.taxonomy !== expected || endpoint !== expected', $control);
        $this->assertStringContainsString('onResetRequested={resetGlobalTaxonomyFilters}', $pages);
        $this->assertStringContainsString("setSearchInput('')", $pages);
        $this->assertStringContainsString("setSearch('')", $pages);
        $this->assertStringContainsString("setTaxonomyType('all')", $pages);
        $this->assertStringContainsString('queryClient.fetchQuery({', $pages);
        $this->assertStringContainsString("endpointWithQuery(trustedEndpoint, 1, '')", $pages);
        $this->assertStringContainsString("Site::query()->findOrFail($site)", $controller);
    }

    public function test_filtered_read_then_reset_returns_unfiltered_actual_tenant_terms(): void
    {
        $alice = User::factory()->create();
        $alpha = $this->membership($alice, 'taxonomy-reset-alpha', ['tenant.view', 'sites.view', 'content.view']);
        $alphaSite = $this->site($alpha->tenant, 'Alpha WP');
        $this->term($alpha->tenant, $alphaSite, 901, 'category', 'Needle', 'needle');
        $this->term($alpha->tenant, $alphaSite, 902, 'post_tag', 'Other Tag', 'other-tag');

        $bob = User::factory()->create();
        $beta = $this->membership($bob, 'taxonomy-reset-beta', ['tenant.view', 'sites.view', 'content.view']);
        $betaSite = $this->site($beta->tenant, 'Beta WP');
        $this->term($beta->tenant, $betaSite, 903, 'category', 'Secret', 'secret');

        $filtered = '/api/v1/tenants/taxonomy-reset-alpha/sites/'.$alphaSite->id.'/taxonomy?page=1&taxonomy=category&search=Needle';
        $unfiltered = '/api/v1/tenants/taxonomy-reset-alpha/sites/'.$alphaSite->id.'/taxonomy?page=1';

        $this->actingAs($alice)->getJson($filtered)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Needle');

        $this->actingAs($alice)->getJson($unfiltered)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);

        $this->actingAs($alice)
            ->getJson('/api/v1/tenants/taxonomy-reset-alpha/sites/'.$betaSite->id.'/taxonomy?page=1')
            ->assertNotFound();
        $this->actingAs($alice)
            ->getJson('/api/v1/tenants/taxonomy-reset-beta/sites/'.$betaSite->id.'/taxonomy?page=1')
            ->assertNotFound();
        $this->assertNotSame($alpha->tenant_id, $beta->tenant_id);
    }

    public function test_missing_permission_is_403_and_foreign_site_is_404(): void
    {
        $owner = User::factory()->create();
        $membership = $this->membership($owner, 'taxonomy-reset-limited', ['tenant.view', 'sites.view']);
        $site = $this->site($membership->tenant, 'Limited WP');
        $this->actingAs($owner)
            ->getJson('/api/v1/tenants/taxonomy-reset-limited/sites/'.$site->id.'/taxonomy?page=1')
            ->assertForbidden();

        auth()->logout();
        $this->getJson('/api/v1/tenants/taxonomy-reset-limited/sites/'.$site->id.'/taxonomy')
            ->assertUnauthorized();
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

    private function term(Tenant $tenant, Site $site, int $remoteId, string $taxonomy, string $name, string $slug): void
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
            ]);
        } finally {
            $context->forget();
        }
    }
}
