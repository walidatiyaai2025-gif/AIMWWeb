<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentExplorerReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalTaxonomyResultExplorerTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-TAXO-602DE140EB';

    public function test_source_contract_exposes_site_explorer_navigation_from_taxonomy_results(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalTaxonomyExplorer.razor'),
        );

        $this->assertStringContainsString('/sites/{item.SiteId}/explorer', $source);
        $this->assertStringContainsString('L.IsArabic ? "المستكشف" : "Explorer"', $source);
    }

    public function test_laravel_control_adapts_the_result_explorer_link_to_the_authoritative_active_site(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/taxonomy-back-to-explorer-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('GLOBAL_TAXONOMY_RESULT_EXPLORER_OPERATION_ID', $control);
        $this->assertStringContainsString('TaxonomyBackToExplorerControl context={context}', $app);
        $this->assertStringContainsString('context.api.taxonomy', $control);
        $this->assertStringContainsString('/explorer', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_explorer_read_is_tenant_site_scoped_and_foreign_site_returns_404(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'global-tax-alpha', ['tenant.view', 'sites.view', 'content.view']);
        $site = $this->site($membership, 'Global Tax Alpha');

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/global-tax-alpha/sites/'.$site->id.'/explorer', 'GET'),
        );
        $this->assertSame(ContentExplorerReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/global-tax-alpha/sites/'.$site->id.'/explorer')
            ->assertOk();

        $foreignUser = User::factory()->create();
        $foreign = $this->membership($foreignUser, 'global-tax-foreign', ['tenant.view', 'sites.view', 'content.view']);
        $foreignSite = $this->site($foreign, 'Global Tax Foreign');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/global-tax-alpha/sites/'.$foreignSite->id.'/explorer')
            ->assertNotFound();
    }

    public function test_missing_sites_view_permission_fails_closed(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'global-tax-limited', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Global Tax Limited');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/global-tax-limited/sites/'.$site->id.'/explorer')
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "global-taxonomy-{$slug}-{$user->id}"]);

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
