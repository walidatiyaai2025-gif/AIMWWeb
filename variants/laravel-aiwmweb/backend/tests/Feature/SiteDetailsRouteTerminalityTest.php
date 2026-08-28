<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteDetailsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_details_route_is_explicit_guarded_and_precedes_spa_fallback(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $routes = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
        $route = $routes->firstWhere('name', 'canonical.site.details');
        $this->assertNotNull($route);
        $this->assertSame('tenants/{tenant}/sites/{site}', $route['uri']);
        $this->assertStringContainsString('GET', $route['method']);
        $middleware = is_array($route['middleware']) ? implode(',', $route['middleware']) : (string) $route['middleware'];
        $this->assertStringContainsString('auth', $middleware);
        $this->assertStringContainsString('tenant.context', $middleware);

        $explicitIndex = $routes->search(fn (array $candidate): bool => $candidate['name'] === 'canonical.site.details');
        $fallbackIndex = $routes->search(fn (array $candidate): bool => $candidate['uri'] === 'tenants/{tenant}/{path?}');
        $this->assertIsInt($explicitIndex);
        $this->assertIsInt($fallbackIndex);
        $this->assertLessThan($fallbackIndex, $explicitIndex);

        $matched = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1', 'GET'));
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showSite',
            ltrim($matched->getActionName(), '\\'),
        );
    }

    public function test_opening_site_details_binds_real_detail_api_for_the_same_tenant_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->tenantMembership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)->getJson('/tenants/alpha/context')->assertOk();
        $context->assertJsonPath('active_site.id', $site->id);
        $api = $context->json('api');
        $this->assertSame(
            "/api/tenants/alpha/sites/{$site->id}",
            $api['sites.detail.'.$site->id] ?? null,
        );

        $backingRoute = Route::getRoutes()->match(Request::create("/api/tenants/alpha/sites/{$site->id}", 'GET'));
        $this->assertSame(
            SiteManagementController::class.'@show',
            ltrim($backingRoute->getActionName(), '\\'),
        );
        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}")
            ->assertOk()
            ->assertJsonPath('id', $site->id)
            ->assertJsonPath('name', 'Alpha Site');
    }

    public function test_site_details_fails_closed_for_foreign_site_and_missing_site_view_permission(): void
    {
        $user = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->tenantMembership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');
        $this->withoutVite();

        $this->actingAs($user)->get('/tenants/alpha/sites/'.$alphaSite->id)->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();

        $limited = User::factory()->create();
        $limitedMembership = $this->tenantMembership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/sites/'.$limitedSite->id)->assertForbidden();
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-details-{$slug}-{$user->id}"]);
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
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
