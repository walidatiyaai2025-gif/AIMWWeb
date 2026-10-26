<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteDiagnosticsController;
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

final class SiteConnectionSiteUrlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-63EDB4BA6F';

    public function test_source_and_laravel_frontend_bind_exact_site_url_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}/connection"', $source);
        $this->assertStringContainsString('@page "/sites/{Id:guid}/operations"', $source);
        $this->assertStringContainsString('<a href="@_site.SiteUrl" target="_blank">', $source);
        $this->assertStringContainsString('<small>@_site.SiteUrl</small>', $source);

        $frontend = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('safeExternalSiteUrl', $frontend);
        $this->assertStringContainsString('target="_blank"', $frontend);
        $this->assertStringContainsString('rel="noopener noreferrer"', $frontend);
        $this->assertStringContainsString('Loading site URL', $frontend);
        $this->assertStringContainsString('Site URL unavailable', $frontend);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@status', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authoritative_site_url_read_is_tenant_scoped_and_read_only(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha', ['tenant.view', 'sites.view']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta Site', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($reader)
            ->getJson("/api/tenants/alpha/sites/{$alphaSite->id}/connection")
            ->assertOk()
            ->assertJsonPath('site.id', $alphaSite->id)
            ->assertJsonPath('site.url', 'https://alpha.test');

        $this->actingAs($reader)
            ->getJson("/api/tenants/alpha/sites/{$betaSite->id}/connection")
            ->assertNotFound();

        $this->assertSame('https://alpha.test', $alphaSite->fresh()->url);
        $this->assertSame('https://beta.test', $betaSite->fresh()->url);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-site-url-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
