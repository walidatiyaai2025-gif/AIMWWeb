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

final class ModuleWorkspaceLoadSitesTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-39472863C8';

    public function test_exact_canonical_operation_is_module_workspace_load_sites_async(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/module-workspace-load-sites-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('private async Task LoadSitesAsync()', $source);
        $this->assertStringContainsString('_sites = await SiteService.GetSitesAsync();', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('/api/tenants/${context.tenant.slug}/sites', $frontend);
        $this->assertStringContainsString('ModuleWorkspaceLoadSitesControl', $app);
        $this->assertStringContainsString("route.key === 'seo-audit' || route.key === 'seo-suggestions'", $app);
    }

    public function test_site_collection_authority_is_authenticated_and_tenant_scoped(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites', 'GET'));

        $this->assertSame(SiteManagementController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_read_returns_only_active_tenant_sites_and_foreign_tenant_fails_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beta = Tenant::query()->create(['slug' => 'beta', 'name' => 'Beta']);

        $context = app(TenantContext::class);
        $context->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha WordPress', 'url' => 'https://alpha.test', 'status' => 'active']);
        $context->forget();

        $context->activate($beta);
        Site::query()->create(['name' => 'Beta WordPress', 'url' => 'https://beta.test', 'status' => 'active']);
        $context->forget();

        $response = $this->actingAs($user)->getJson('/api/tenants/alpha/sites')->assertOk();
        $response->assertJsonFragment(['id' => $alphaSite->id, 'name' => 'Alpha WordPress']);
        $this->assertStringNotContainsString('Beta WordPress', $response->getContent());

        $this->actingAs($user)->getJson('/api/tenants/beta/sites')->assertNotFound();
    }

    public function test_load_sites_control_is_read_only_and_contains_no_fake_site_seed(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/module-workspace-load-sites-control.tsx'));

        $this->assertStringNotContainsString("method: 'POST'", $frontend);
        $this->assertStringNotContainsString("method: 'PUT'", $frontend);
        $this->assertStringNotContainsString("method: 'PATCH'", $frontend);
        $this->assertStringNotContainsString("method: 'DELETE'", $frontend);
        $this->assertStringNotContainsString('sample site', strtolower($frontend));
        $this->assertStringContainsString('No sites available', $frontend);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "module-sites-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
