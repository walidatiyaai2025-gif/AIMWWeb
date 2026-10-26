<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteManagementController;
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

final class ModuleWorkspaceDynamicSiteLinksTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-E4D7C73D4E';

    public function test_source_semantics_are_the_dynamic_site_navigation_controls(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor'),
        );

        $this->assertStringContainsString('href="@($"/sites/{site.Id}/seo")"', $source);
        $this->assertStringContainsString('href="@($"/sites/{site.Id}/explorer")"', $source);
        $this->assertStringContainsString('Open SEO audit', $source);
        $this->assertStringContainsString('Content explorer', $source);
    }

    public function test_laravel_control_preserves_site_identity_on_both_real_destinations(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/module-workspace-load-sites-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, `/sites/${site.id}/seo`)", $frontend);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, `/explorer?site=${site.id}`)", $frontend);
        $this->assertStringContainsString('data-testid="seo-site-link"', $frontend);
        $this->assertStringContainsString('data-testid="seo-site-explorer-link"', $frontend);
        $this->assertStringContainsString('ModuleWorkspaceLoadSitesControl', $app);
        $this->assertStringContainsString("route.key === 'seo-audit' || route.key === 'seo-suggestions'", $app);
    }

    public function test_dynamic_links_are_gated_by_the_authoritative_tenant_site_collection(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites', 'GET'));

        $this->assertSame(SiteManagementController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beta = Tenant::query()->create(['slug' => 'beta', 'name' => 'Beta']);

        $context = app(TenantContext::class);
        $context->activate($alpha);
        Site::query()->create(['name' => 'Alpha WordPress', 'url' => 'https://alpha.test', 'status' => 'active']);
        $context->forget();

        $context->activate($beta);
        Site::query()->create(['name' => 'Beta WordPress', 'url' => 'https://beta.test', 'status' => 'active']);
        $context->forget();

        $this->actingAs($user)->getJson('/api/tenants/alpha/sites')->assertOk();
        $this->actingAs($user)->getJson('/api/tenants/beta/sites')->assertNotFound();
    }

    public function test_operation_is_navigation_only_and_adds_no_mutation_surface(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/module-workspace-load-sites-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringNotContainsString("method: 'POST'", $frontend);
        $this->assertStringNotContainsString("method: 'PUT'", $frontend);
        $this->assertStringNotContainsString("method: 'PATCH'", $frontend);
        $this->assertStringNotContainsString("method: 'DELETE'", $frontend);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "module-dynamic-links-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
