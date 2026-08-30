<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\LegacyNotificationReadController;
use App\Http\Controllers\LoginReadController;
use App\Http\Controllers\PlatformReadController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteApiProvenanceGapTest extends TestCase
{
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
}
