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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteApiProvenanceGapContractTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTED_ROUTES = [
        'AIMW-COMM-A16719E105' => [
            'source' => '/sites/{SiteId:guid}/comments',
            'name' => 'canonical.site.comments',
            'uri' => 'tenants/{tenant}/sites/{site}/comments',
            'action' => CanonicalWorkspaceRouteController::class.'@redirectSite',
        ],
        'AIMW-MEDI-8BADBE1261' => [
            'source' => '/sites/{SiteId:guid}/media',
            'name' => 'canonical.site.media',
            'uri' => 'tenants/{tenant}/sites/{site}/media',
            'action' => CanonicalWorkspaceRouteController::class.'@redirectSite',
        ],
        'AIMW-TAXO-CDC6948A06' => [
            'source' => '/sites/{SiteId:guid}/taxonomy',
            'name' => 'canonical.site.taxonomy',
            'uri' => 'tenants/{tenant}/sites/{site}/taxonomy',
            'action' => CanonicalWorkspaceRouteController::class.'@redirectSite',
        ],
        'AIMW-AI-C37F405767' => [
            'source' => '/sites/{Id:guid}',
            'name' => 'canonical.site.details',
            'uri' => 'tenants/{tenant}/sites/{site}',
            'action' => CanonicalWorkspaceRouteController::class.'@showSite',
        ],
    ];

    private const APIS = [
        'AIMW-EMAI-2D94EFDD53' => [
            'source' => '/api/notifications',
            'name' => 'canonical.api.legacy-notifications',
            'uri' => 'api/notifications',
            'action' => LegacyNotificationReadController::class.'@index',
            'auth' => true,
        ],
        'AIMW-OPER-ABB41FC891' => [
            'source' => '/login',
            'name' => 'login',
            'uri' => 'login',
            'action' => LoginReadController::class,
            'auth' => false,
        ],
        'AIMW-PLAT-A91A2B0B11' => [
            'source' => '/api/build',
            'name' => 'canonical.api.build',
            'uri' => 'api/build',
            'action' => PlatformReadController::class.'@build',
            'auth' => true,
        ],
        'AIMW-PLAT-FAC7505B26' => [
            'source' => '/api/dashboard',
            'name' => 'canonical.api.dashboard',
            'uri' => 'api/dashboard',
            'action' => PlatformReadController::class.'@dashboard',
            'auth' => true,
        ],
    ];

    public function test_exact_canonical_ids_are_bound_to_the_existing_adapted_routes_and_apis(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $byId = collect($payload['operations'])->keyBy('operation_id');

        foreach (self::ADAPTED_ROUTES as $operationId => $contract) {
            $this->assertArrayHasKey($operationId, $byId);
            $row = $byId[$operationId];
            $this->assertSame('route', $row['kind']);
            $this->assertSame($contract['source'], $row['route_screen']);
            $this->assertContains($row['migration_state'], ['PENDING', 'ADAPTED']);
        }
        foreach (self::APIS as $operationId => $contract) {
            $this->assertArrayHasKey($operationId, $byId);
            $row = $byId[$operationId];
            $this->assertSame('api', $row['kind']);
            $this->assertSame($contract['source'], $row['route_screen']);
            $this->assertContains($row['migration_state'], ['PENDING', 'ADAPTED']);
        }

        Artisan::call('route:list', ['--json' => true]);
        $listed = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR))->keyBy('name');
        foreach ([...self::ADAPTED_ROUTES, ...self::APIS] as $contract) {
            $this->assertArrayHasKey($contract['name'], $listed);
            $this->assertSame($contract['uri'], $listed[$contract['name']]['uri']);
            $this->assertStringContainsString('GET', $listed[$contract['name']]['method']);
        }
    }

    public function test_adapted_site_routes_fail_closed_for_foreign_site_and_missing_permission(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');
        $this->withoutVite();

        foreach (['comments' => 'comments', 'media' => 'media', 'taxonomy' => 'taxonomy'] as $path => $target) {
            $this->actingAs($user)
                ->get('/tenants/alpha/sites/'.$alphaSite->id.'/'.$path)
                ->assertRedirect('/tenants/alpha/module/'.$target.'?site='.$alphaSite->id);
            $this->actingAs($user)
                ->get('/tenants/alpha/sites/'.$betaSite->id.'/'.$path)
                ->assertNotFound();
        }

        $this->actingAs($user)->get('/tenants/alpha/sites/'.$alphaSite->id)->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/sites/'.$limitedSite->id)->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/sites/'.$limitedSite->id.'/comments')->assertForbidden();
    }

    public function test_api_routes_preserve_real_controller_and_authentication_contracts(): void
    {
        $paths = [
            '/api/notifications' => [LegacyNotificationReadController::class.'@index', true],
            '/api/build' => [PlatformReadController::class.'@build', true],
            '/api/dashboard' => [PlatformReadController::class.'@dashboard', true],
            '/login' => [LoginReadController::class, false],
        ];

        foreach ($paths as $path => [$action, $requiresAuth]) {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
            $this->assertSame($action, ltrim($route->getActionName(), '\\'));
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware);
            if ($requiresAuth) {
                $this->assertContains('auth', $middleware);
                $this->getJson($path)->assertUnauthorized();
            } else {
                $this->assertNotContains('auth', $middleware);
                $this->assertNotContains('tenant.context', $middleware);
                $this->get($path)->assertOk();
            }
        }
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
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
