<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\ContentApiController;
use App\Http\Controllers\EmailNotificationController;
use App\Http\Controllers\RouteApiAdapterController;
use App\Http\Controllers\SiteManagementController;
use App\Http\Controllers\SyncApiController;
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

class RouteApiTerminalityInventoryTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_PASS = [
        'AIMW-CONT-D828690844' => '/sites',
        'AIMW-EMAI-2E95AF6C05' => '/notifications',
        'AIMW-EMAI-78352CD34E' => '/email/history',
        'AIMW-BACK-66BFA49775' => '/module/backups',
        'AIMW-CONT-DF483546DA' => '/module/logs',
        'AIMW-CONT-FBD0368CAA' => '/operations',
        'AIMW-CONT-BB5B32880A' => '/admin/application-users',
        'AIMW-CONT-1DA83B9262' => '/settings/sessions',
        'AIMW-CONT-9B8574AA90' => '/logs',
        'AIMW-CONT-E14274269E' => '/operations/hub',
        'AIMW-BACK-979DEF54FA' => '/backups',
    ];

    private const SECOND_PASS = [
        'AIMW-CONT-4A295D45D4' => '/module/posts',
        'AIMW-CONT-EA0DDC0ABE' => '/module/pages',
        'AIMW-MEDI-BF81D0B635' => '/module/media',
        'AIMW-COMM-C2DDF5DAE3' => '/module/comments',
        'AIMW-TAXO-AEEE1025B9' => '/module/taxonomy',
        'AIMW-SYNC-1C799B7D70' => '/module/sync',
        'AIMW-COMM-A16719E105' => '/sites/{SiteId:guid}/comments',
        'AIMW-MEDI-8BADBE1261' => '/sites/{SiteId:guid}/media',
        'AIMW-TAXO-CDC6948A06' => '/sites/{SiteId:guid}/taxonomy',
        'AIMW-CONT-5D18F49928' => '/module/reports',
        'AIMW-CONT-8140D785B5' => '/reports',
        'AIMW-CONT-9B87A269F3' => '/site-operations',
        'AIMW-CONT-D76D83682F' => '/operations/sites',
        'AIMW-AUTO-38567579D6' => '/automation-center',
        'AIMW-AUTO-F12BC80C1B' => '/automation-schedules',
        'AIMW-AUTO-1546E5BCAF' => '/module/schedules',
        'AIMW-AUTO-6522502C20' => '/execution-center',
        'AIMW-AUTO-968FD60A95' => '/module/execution',
    ];

    private const SECOND_PASS_INVENTORY_SHA256 = '266f461bae43748c229ba04a26d73287fdd8b0e2026844403895864bdd5a0174';

    private const ROUTE_NAMES = [
        'canonical.workspace.sites' => 'tenants/{tenant}/sites',
        'canonical.workspace.notifications' => 'tenants/{tenant}/notifications',
        'canonical.workspace.email-history' => 'tenants/{tenant}/email/history',
        'canonical.workspace.backups' => 'tenants/{tenant}/module/backups',
        'canonical.workspace.logs' => 'tenants/{tenant}/module/logs',
        'canonical.workspace.operations' => 'tenants/{tenant}/operations',
        'canonical.alias.application-users' => 'tenants/{tenant}/admin/application-users',
        'canonical.alias.settings-sessions' => 'tenants/{tenant}/settings/sessions',
        'canonical.alias.logs' => 'tenants/{tenant}/logs',
        'canonical.alias.operations-hub' => 'tenants/{tenant}/operations/hub',
        'canonical.alias.backups' => 'tenants/{tenant}/backups',
        'canonical.workspace.posts' => 'tenants/{tenant}/module/posts',
        'canonical.workspace.pages' => 'tenants/{tenant}/module/pages',
        'canonical.workspace.media' => 'tenants/{tenant}/module/media',
        'canonical.workspace.comments' => 'tenants/{tenant}/module/comments',
        'canonical.workspace.taxonomy' => 'tenants/{tenant}/module/taxonomy',
        'canonical.workspace.sync' => 'tenants/{tenant}/module/sync',
        'canonical.site.comments' => 'tenants/{tenant}/sites/{site}/comments',
        'canonical.site.media' => 'tenants/{tenant}/sites/{site}/media',
        'canonical.site.taxonomy' => 'tenants/{tenant}/sites/{site}/taxonomy',
        'canonical.workspace.reports' => 'tenants/{tenant}/module/reports',
        'canonical.alias.reports' => 'tenants/{tenant}/reports',
        'canonical.workspace.site-operations' => 'tenants/{tenant}/site-operations',
        'canonical.alias.operations-sites' => 'tenants/{tenant}/operations/sites',
        'canonical.workspace.automation' => 'tenants/{tenant}/automation-center',
        'canonical.workspace.schedules' => 'tenants/{tenant}/module/schedules',
        'canonical.alias.automation-schedules' => 'tenants/{tenant}/automation-schedules',
        'canonical.workspace.execution' => 'tenants/{tenant}/module/execution',
        'canonical.alias.execution-center' => 'tenants/{tenant}/execution-center',
    ];

    private const ALL_PERMISSIONS = [
        'tenant.view', 'sites.view', 'notifications.view', 'tenant.manage', 'diagnostics.view',
        'backup.manage', 'backups.view', 'operations.manage', 'execution.view', 'users.view',
        'sessions.manage', 'sessions.view', 'content.view', 'sync.view', 'reports.view', 'automation.view',
    ];

    public function test_live_reconciliation_inventory_and_both_passes_claim_only_real_pending_rows(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($path);
        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $pending = collect($payload['operations'])
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->filter(fn (array $operation): bool => in_array($operation['kind'], ['route', 'api'], true));

        $this->assertCount(92, $pending);
        $this->assertSame(84, $pending->where('kind', 'route')->count());
        $this->assertSame(8, $pending->where('kind', 'api')->count());

        $byId = $pending->keyBy('operation_id');
        foreach (self::FIRST_PASS + self::SECOND_PASS as $operationId => $sourcePath) {
            $this->assertArrayHasKey($operationId, $byId);
            $this->assertSame('route', $byId[$operationId]['kind']);
            $this->assertSame($sourcePath, $byId[$operationId]['route_screen']);
            $this->assertFalse((bool) $byId[$operationId]['mutation']);
        }

        $this->assertCount(29, self::FIRST_PASS + self::SECOND_PASS);
        $this->assertCount(18, self::SECOND_PASS);
    }

    public function test_second_pass_inventory_is_exact_and_deterministic(): void
    {
        $payload = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $remaining = collect($payload['operations'])
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->filter(fn (array $operation): bool => in_array($operation['kind'], ['route', 'api'], true))
            ->reject(fn (array $operation): bool => array_key_exists($operation['operation_id'], self::FIRST_PASS))
            ->values();

        $this->assertCount(81, $remaining);
        $ids = $remaining->pluck('operation_id')->all();
        $uniqueIds = array_values(array_unique($ids));
        $this->assertCount(81, $uniqueIds);
        sort($uniqueIds, SORT_STRING);
        $this->assertSame(self::SECOND_PASS_INVENTORY_SHA256, hash('sha256', implode("\n", $uniqueIds)));
        $this->assertContains('AIMW-CONT-2F2E40D7F0', $ids);
        $this->assertContains('AIMW-CONT-270F69CE9A', $ids);
        $this->assertArrayNotHasKey('AIMW-CONT-2F2E40D7F0', self::SECOND_PASS);
        $this->assertArrayNotHasKey('AIMW-CONT-270F69CE9A', self::SECOND_PASS);
    }

    public function test_artisan_route_list_contains_explicit_guarded_canonical_routes_before_fallback(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $routes = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
        $listed = $routes->keyBy('name');
        $fallbackIndex = $routes->search(fn (array $route): bool => $route['uri'] === 'tenants/{tenant}/{path?}');
        $this->assertIsInt($fallbackIndex);

        foreach (self::ROUTE_NAMES as $name => $uri) {
            $this->assertArrayHasKey($name, $listed);
            $this->assertSame($uri, $listed[$name]['uri']);
            $this->assertStringContainsString('GET', $listed[$name]['method']);
            $middleware = is_array($listed[$name]['middleware']) ? implode(',', $listed[$name]['middleware']) : (string) $listed[$name]['middleware'];
            $this->assertStringContainsString('auth', $middleware);
            $this->assertStringContainsString('tenant.context', $middleware);
            $index = $routes->search(fn (array $route): bool => $route['uri'] === $uri);
            $this->assertIsInt($index);
            $this->assertLessThan($fallbackIndex, $index, $uri.' must precede the generic SPA fallback.');
        }
    }

    public function test_backing_routes_resolve_to_real_controllers_and_are_tenant_guarded(): void
    {
        $contracts = [
            '/api/tenants/alpha/sites' => SiteManagementController::class.'@index',
            '/api/v1/tenants/alpha/notifications' => EmailNotificationController::class.'@index',
            '/api/v1/tenants/alpha/email/deliveries' => EmailNotificationController::class.'@deliveries',
            '/api/v1/tenants/alpha/sites/1/content/post' => ContentApiController::class.'@index',
            '/api/v1/tenants/alpha/sites/1/media' => ContentApiController::class.'@media',
            '/api/v1/tenants/alpha/sites/1/comments' => ContentApiController::class.'@comments',
            '/api/v1/tenants/alpha/sites/1/taxonomy' => ContentApiController::class.'@taxonomy',
            '/api/v1/tenants/alpha/sites/1/sync' => SyncApiController::class.'@index',
            '/tenants/alpha/admin/backups' => AdminOperationsController::class.'@backups',
            '/tenants/alpha/admin/logs' => AdminOperationsController::class.'@logs',
            '/tenants/alpha/admin/operations' => AdminOperationsController::class.'@operations',
            '/tenants/alpha/admin/automations' => AdminOperationsController::class.'@automations',
            '/tenants/alpha/admin/schedules' => AdminOperationsController::class.'@schedules',
            '/tenants/alpha/route-api/report-exports' => RouteApiAdapterController::class.'@reportExports',
            '/tenants/alpha/route-api/site-operations' => RouteApiAdapterController::class.'@siteOperations',
        ];

        foreach ($contracts as $uri => $action) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertSame($action, $route->getActionName(), $uri);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('tenant.context', $middleware, $uri);
            $this->assertTrue(in_array('auth', $middleware, true) || in_array('web', $middleware, true), $uri);
        }
    }

    public function test_canonical_routes_do_not_shadow_existing_backend_json_or_mutation_routes(): void
    {
        $this->assertSame(AdminOperationsController::class.'@sessions', Route::getRoutes()->match(Request::create('/tenants/alpha/admin/sessions', 'GET'))->getActionName());
        $this->assertSame(AdminOperationsController::class.'@roles', Route::getRoutes()->match(Request::create('/tenants/alpha/admin/roles', 'GET'))->getActionName());
        $this->assertSame(AdminOperationsController::class.'@queueExport', Route::getRoutes()->match(Request::create('/tenants/alpha/admin/reports/exports', 'POST'))->getActionName());
        $this->assertSame(ContentApiController::class.'@index', Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/1/content/post', 'GET'))->getActionName());
    }

    public function test_site_bound_workspaces_publish_fully_bound_real_api_contracts(): void
    {
        $user = User::factory()->create();
        $membership = $this->tenantMembership($user, 'alpha', self::ALL_PERMISSIONS);
        $site = $this->site($membership, 'Alpha site');
        $this->withoutVite();

        $this->actingAs($user)->get('/tenants/alpha/module/posts?site='.$site->id)->assertOk()->assertSee('id="app"', false);
        $response = $this->actingAs($user)->getJson('/tenants/alpha/context')->assertOk();
        $api = $response->json('api');
        $expected = [
            'posts' => "/api/v1/tenants/alpha/sites/{$site->id}/content/post",
            'pages' => "/api/v1/tenants/alpha/sites/{$site->id}/content/page",
            'media' => "/api/v1/tenants/alpha/sites/{$site->id}/media",
            'comments' => "/api/v1/tenants/alpha/sites/{$site->id}/comments",
            'taxonomy' => "/api/v1/tenants/alpha/sites/{$site->id}/taxonomy",
            'sync' => "/api/v1/tenants/alpha/sites/{$site->id}/sync",
        ];
        foreach ($expected as $key => $url) {
            $this->assertSame($url, $api[$key] ?? null, $key);
            $this->assertStringNotContainsString('{site}', (string) ($api[$key] ?? ''));
            $this->actingAs($user)->getJson($url)->assertOk();
        }
        $response->assertJsonPath('active_site.id', $site->id);
    }

    public function test_site_binding_fails_closed_for_missing_ambiguous_foreign_invalid_and_stale_sites(): void
    {
        $user = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'alpha', self::ALL_PERMISSIONS);
        $beta = $this->tenantMembership($user, 'beta', self::ALL_PERMISSIONS);
        $foreign = $this->site($beta, 'Foreign site');
        $this->withoutVite();

        $this->actingAs($user)->get('/tenants/alpha/module/posts')->assertStatus(409);
        $this->actingAs($user)->get('/tenants/alpha/module/posts?site=bogus')->assertStatus(422);
        $this->actingAs($user)->get('/tenants/alpha/module/posts?site='.$foreign->id)->assertNotFound();

        $one = $this->site($alpha, 'One');
        $this->actingAs($user)->get('/tenants/alpha/module/posts?site='.$one->id)->assertOk();
        $this->deleteSite($alpha, $one);
        $this->actingAs($user)->get('/tenants/alpha/module/posts')->assertNotFound();

        $two = $this->site($alpha, 'Two');
        $three = $this->site($alpha, 'Three');
        $this->assertNotSame($two->id, $three->id);
        $this->withSession(['canonical_site_id' => null]);
        $this->actingAs($user)->get('/tenants/alpha/module/posts')->assertStatus(409);
    }

    public function test_site_specific_aliases_validate_site_ownership_before_redirecting(): void
    {
        $user = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'alpha', self::ALL_PERMISSIONS);
        $beta = $this->tenantMembership($user, 'beta', self::ALL_PERMISSIONS);
        $site = $this->site($alpha, 'Alpha site');
        $foreign = $this->site($beta, 'Beta site');

        foreach (['comments' => '/module/comments', 'media' => '/module/media', 'taxonomy' => '/module/taxonomy'] as $source => $target) {
            $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/{$source}")
                ->assertRedirect("/tenants/alpha{$target}?site={$site->id}");
            $this->actingAs($user)->get("/tenants/alpha/sites/{$foreign->id}/{$source}")->assertNotFound();
        }
    }

    public function test_second_pass_read_routes_use_real_services_and_preserve_read_only_report_semantics(): void
    {
        $user = User::factory()->create();
        $this->tenantMembership($user, 'alpha', self::ALL_PERMISSIONS);
        $this->withoutVite();

        foreach (['/module/reports', '/site-operations', '/automation-center', '/module/schedules', '/module/execution'] as $path) {
            $this->actingAs($user)->get('/tenants/alpha'.$path)->assertOk()->assertSee('id="app"', false);
        }
        foreach (['/reports' => '/module/reports', '/operations/sites' => '/site-operations', '/automation-schedules' => '/module/schedules', '/execution-center' => '/module/execution'] as $source => $target) {
            $this->actingAs($user)->get('/tenants/alpha'.$source)->assertRedirect('/tenants/alpha'.$target);
        }

        $this->actingAs($user)->getJson('/tenants/alpha/admin/automations')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/admin/schedules')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/admin/operations')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/route-api/report-exports')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/route-api/site-operations')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_routes_fail_closed_for_wrong_tenant_or_missing_view_and_service_permissions(): void
    {
        $authorized = User::factory()->create();
        $this->tenantMembership($authorized, 'alpha', self::ALL_PERMISSIONS);
        $outsider = User::factory()->create();
        $this->tenantMembership($outsider, 'beta', self::ALL_PERMISSIONS);
        $limited = User::factory()->create();
        $this->tenantMembership($limited, 'limited', ['tenant.view']);
        $this->withoutVite();

        foreach (['/sites', '/notifications', '/email/history', '/module/backups', '/module/logs', '/operations', '/admin/users', '/account/sessions'] as $path) {
            $this->actingAs($authorized)->get('/tenants/alpha'.$path)->assertOk()->assertSee('id="app"', false);
            $this->actingAs($limited)->get('/tenants/limited'.$path)->assertForbidden();
        }
        $this->actingAs($limited)->get('/tenants/limited/module/reports')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/automation-center')->assertForbidden();
        $this->actingAs($authorized)->get('/tenants/beta/sites')->assertNotFound();
    }

    public function test_first_pass_terminal_routes_remain_backed_by_live_service_responses(): void
    {
        $user = User::factory()->create();
        $this->tenantMembership($user, 'alpha', self::ALL_PERMISSIONS);

        $this->actingAs($user)->getJson('/api/tenants/alpha/sites')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/tenants/alpha/notifications')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/tenants/alpha/email/deliveries')->assertOk();
        $this->actingAs($user)->getJson('/tenants/alpha/admin/backups')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/admin/logs')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/admin/operations')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/admin/members')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($user)->getJson('/tenants/alpha/admin/sessions')->assertOk()->assertJsonStructure(['data']);
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "route-api-{$slug}"]);
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
        $context->activate($membership->tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function deleteSite(TenantMembership $membership, Site $site): void
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant);
        Site::query()->findOrFail($site->id)->delete();
        $context->forget();
    }
}
