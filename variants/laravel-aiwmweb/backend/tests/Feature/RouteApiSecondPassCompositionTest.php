<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\ContentApiController;
use App\Http\Controllers\RouteApiAdapterController;
use App\Http\Controllers\SyncApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteApiSecondPassCompositionTest extends TestCase
{
    private const OPERATIONS = [
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
        'AIMW-BILL-2FFFC55BAB' => '/account/billing',
        'AIMW-CONT-FB7F9189C0' => '/account/profile',
    ];

    private const ROUTE_NAMES = [
        'canonical.workspace.account-profile' => 'tenants/{tenant}/account/profile',
        'canonical.workspace.account-billing' => 'tenants/{tenant}/account/billing',
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

    private const SPECIALIZED_REPORT_ROUTES = [
        'canonical.workspace.reports',
        'canonical.alias.reports',
    ];

    public function test_second_pass_inventory_survives_composition_without_stale_pending_counts(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($path);
        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $byId = collect($payload['operations'])->keyBy('operation_id');

        $this->assertCount(20, self::OPERATIONS);
        foreach (self::OPERATIONS as $operationId => $sourcePath) {
            $this->assertArrayHasKey($operationId, $byId);
            $operation = $byId[$operationId];
            $this->assertSame('route', $operation['kind']);
            $this->assertSame($sourcePath, $operation['route_screen']);
            $this->assertFalse((bool) $operation['mutation']);
            $this->assertContains($operation['migration_state'], ['PENDING', 'ADAPTED', 'PORTED']);
        }
    }

    public function test_second_pass_canonical_routes_are_explicit_guarded_and_precede_spa_fallback(): void
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
            $middleware = is_array($listed[$name]['middleware'])
                ? implode(',', $listed[$name]['middleware'])
                : (string) $listed[$name]['middleware'];
            $this->assertStringContainsString('tenant.context', $middleware);
            if (in_array($name, self::SPECIALIZED_REPORT_ROUTES, true)) {
                $this->assertStringContainsString('web', $middleware);
            } else {
                $this->assertStringContainsString('auth', $middleware);
            }
            $index = $routes->search(fn (array $route): bool => $route['uri'] === $uri);
            $this->assertIsInt($index);
            $this->assertLessThan($fallbackIndex, $index, $uri.' must precede the generic SPA fallback.');
        }
    }

    public function test_second_pass_backing_contracts_resolve_to_real_services(): void
    {
        $contracts = [
            '/api/v1/tenants/alpha/sites/1/content/post' => ContentApiController::class.'@index',
            '/api/v1/tenants/alpha/sites/1/media' => ContentApiController::class.'@media',
            '/api/v1/tenants/alpha/sites/1/comments' => ContentApiController::class.'@comments',
            '/api/v1/tenants/alpha/sites/1/taxonomy' => ContentApiController::class.'@taxonomy',
            '/api/v1/tenants/alpha/sites/1/sync' => SyncApiController::class.'@index',
            '/tenants/alpha/admin/automations' => AdminOperationsController::class.'@automations',
            '/tenants/alpha/admin/schedules' => AdminOperationsController::class.'@schedules',
            '/tenants/alpha/route-api/report-exports' => RouteApiAdapterController::class.'@reportExports',
            '/tenants/alpha/route-api/site-operations' => RouteApiAdapterController::class.'@siteOperations',
            '/tenants/alpha/route-api/billing-overview' => RouteApiAdapterController::class.'@billingOverview',
            '/tenants/alpha/route-api/account-profile' => RouteApiAdapterController::class.'@accountProfile',
        ];

        foreach ($contracts as $uri => $action) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertSame($action, $route->getActionName(), $uri);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('tenant.context', $middleware, $uri);
            $this->assertTrue(in_array('auth', $middleware, true) || in_array('web', $middleware, true), $uri);
        }
    }

    public function test_canonical_read_routes_do_not_shadow_existing_json_or_mutation_routes(): void
    {
        $this->assertSame(
            AdminOperationsController::class.'@sessions',
            Route::getRoutes()->match(Request::create('/tenants/alpha/admin/sessions', 'GET'))->getActionName(),
        );
        $this->assertSame(
            AdminOperationsController::class.'@queueExport',
            Route::getRoutes()->match(Request::create('/tenants/alpha/admin/reports/exports', 'POST'))->getActionName(),
        );
        $this->assertSame(
            ContentApiController::class.'@index',
            Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/1/content/post', 'GET'))->getActionName(),
        );
    }
}
