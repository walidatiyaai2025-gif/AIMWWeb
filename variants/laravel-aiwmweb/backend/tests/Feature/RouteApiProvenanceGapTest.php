<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\LegacyNotificationReadController;
use App\Http\Controllers\LoginReadController;
use App\Http\Controllers\PlatformReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteApiProvenanceGapTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACTS = [
        'AIMW-COMM-A16719E105' => ['canonical.site.comments', CanonicalWorkspaceRouteController::class],
        'AIMW-MEDI-8BADBE1261' => ['canonical.site.media', CanonicalWorkspaceRouteController::class],
        'AIMW-TAXO-CDC6948A06' => ['canonical.site.taxonomy', CanonicalWorkspaceRouteController::class],
        'AIMW-AI-C37F405767' => ['canonical.site.details', CanonicalWorkspaceRouteController::class],
        'AIMW-EMAI-2D94EFDD53' => ['canonical.api.legacy-notifications', LegacyNotificationReadController::class],
        'AIMW-OPER-ABB41FC891' => ['login', LoginReadController::class],
        'AIMW-PLAT-A91A2B0B11' => ['canonical.api.build', PlatformReadController::class],
        'AIMW-PLAT-FAC7505B26' => ['canonical.api.dashboard', PlatformReadController::class],
    ];

    public function test_exact_canonical_ids_bind_to_real_named_routes_and_declared_actions(): void
    {
        $this->assertCount(8, self::CONTRACTS);
        $this->assertCount(8, array_unique(array_keys(self::CONTRACTS)));

        foreach (self::CONTRACTS as $operationId => [$routeName, $controller]) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $operationId);
            $this->assertStringContainsString($controller, ltrim($route->getActionName(), '\\'), $operationId);
            $this->assertContains('GET', $route->methods(), $operationId);
        }
    }

    public function test_tenant_bound_routes_keep_auth_and_tenant_context_while_login_is_explicitly_tenant_neutral(): void
    {
        foreach (['canonical.site.comments', 'canonical.site.media', 'canonical.site.taxonomy', 'canonical.site.details'] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('auth', $middleware, $routeName);
            $this->assertContains('tenant.context', $middleware, $routeName);
        }

        foreach (['canonical.api.legacy-notifications', 'canonical.api.build', 'canonical.api.dashboard'] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('auth', $middleware, $routeName);
        }

        $login = Route::getRoutes()->getByName('login');
        $this->assertNotNull($login);
        $this->assertContains('web', $login->gatherMiddleware());
        $this->assertNotContains('auth', $login->gatherMiddleware());
        $this->assertNotContains('tenant.context', $login->gatherMiddleware());
        $this->assertSame([], $login->parameterNames());
    }

    public function test_site_aliases_are_tenant_scoped_and_fail_closed_for_permission_and_foreign_ids(): void
    {
        $user = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'alpha', ['tenant.view', 'content.view']);
        $beta = $this->tenantMembership($user, 'beta', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');
        $this->withoutVite();

        foreach ([
            'comments' => '/module/comments',
            'media' => '/module/media',
            'taxonomy' => '/module/taxonomy',
        ] as $suffix => $target) {
            $this->actingAs($user)
                ->get("/tenants/alpha/sites/{$alphaSite->id}/{$suffix}")
                ->assertRedirect('/tenants/alpha'.$target);
            $this->actingAs($user)
                ->get("/tenants/alpha/sites/{$betaSite->id}/{$suffix}")
                ->assertNotFound();
        }

        $limited = User::factory()->create();
        $limitedMembership = $this->tenantMembership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->get("/tenants/limited/sites/{$limitedSite->id}/comments")
            ->assertForbidden();

        $this->get("/tenants/alpha/sites/{$alphaSite->id}/comments")->assertRedirect('/login');
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "route-api-provenance-{$slug}-{$user->id}"]);
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
