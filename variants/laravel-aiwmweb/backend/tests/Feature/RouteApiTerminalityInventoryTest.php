<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\EmailNotificationController;
use App\Http\Controllers\SiteManagementController;
use App\Models\Permission;
use App\Models\Role;
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

    private const TERMINALIZED = [
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
    ];

    private const ALL_PERMISSIONS = [
        'tenant.view', 'sites.view', 'notifications.view', 'tenant.manage', 'diagnostics.view',
        'backup.manage', 'backups.view', 'operations.manage', 'execution.view', 'users.view',
        'sessions.manage', 'sessions.view',
    ];

    public function test_live_reconciliation_has_expected_pending_inventory_and_only_real_rows_are_claimed(): void
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
        foreach (self::TERMINALIZED as $operationId => $sourcePath) {
            $this->assertArrayHasKey($operationId, $byId);
            $this->assertSame('route', $byId[$operationId]['kind']);
            $this->assertSame($sourcePath, $byId[$operationId]['route_screen']);
            $this->assertFalse((bool) $byId[$operationId]['mutation']);
        }
    }

    public function test_second_pass_inventory_is_exact_and_deterministic(): void
    {
        $path = base_path('../docs/operation-parity-reconciliation.json');
        $this->assertFileExists($path);
        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $remaining = collect($payload['operations'])
            ->filter(fn (array $operation): bool => $operation['migration_state'] === 'PENDING')
            ->filter(fn (array $operation): bool => in_array($operation['kind'], ['route', 'api'], true))
            ->reject(fn (array $operation): bool => array_key_exists($operation['operation_id'], self::TERMINALIZED))
            ->map(fn (array $operation): array => [
                'id' => $operation['operation_id'],
                'domain' => $operation['domain'],
                'kind' => $operation['kind'],
                'path' => $operation['route_screen'],
                'control' => $operation['visible_control'],
                'source' => $operation['current_source'],
                'tenant' => (bool) $operation['tenant_owned'],
            ])
            ->values();

        fwrite(STDERR, "\nSECOND_PASS_ROUTE_API_BEGIN\n");
        foreach ($remaining as $operation) {
            fwrite(STDERR, implode('|', [
                $operation['id'],
                $operation['domain'],
                $operation['kind'],
                str_replace('|', '/', (string) $operation['path']),
                str_replace('|', '/', (string) $operation['control']),
                $operation['tenant'] ? 'tenant' : 'global',
            ])."\n");
        }
        fwrite(STDERR, "SECOND_PASS_ROUTE_API_END\n");

        $this->assertCount(81, $remaining);

        $ids = $remaining->pluck('id')->all();
        $uniqueIds = array_values(array_unique($ids));
        $this->assertCount(81, $uniqueIds, 'Second-pass route/API inventory contains duplicate operation IDs.');

        $invalidKinds = $remaining
            ->reject(fn (array $operation): bool => in_array($operation['kind'], ['route', 'api'], true))
            ->pluck('kind')
            ->unique()
            ->values()
            ->all();
        $this->assertSame([], $invalidKinds, 'Second-pass inventory contains a kind outside route/api.');

        sort($uniqueIds, SORT_STRING);
        $this->assertSame(
            self::SECOND_PASS_INVENTORY_SHA256,
            hash('sha256', implode("\n", $uniqueIds)),
            'Second-pass route/API operation inventory drifted from the exact reviewed 81-row set.'
        );

        $this->assertContains('AIMW-CONT-2F2E40D7F0', $ids, 'POST /login must remain outside this PR because PR #283 owns its terminality.');
        $this->assertContains('AIMW-CONT-270F69CE9A', $ids, 'POST /logout must remain outside this PR because PR #283 owns its terminality.');
        $this->assertArrayNotHasKey('AIMW-CONT-2F2E40D7F0', self::TERMINALIZED);
        $this->assertArrayNotHasKey('AIMW-CONT-270F69CE9A', self::TERMINALIZED);
    }

    public function test_artisan_route_list_contains_explicit_guarded_canonical_routes(): void
    {
        Artisan::call('route:list', ['--json' => true]);
        $listed = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR))->keyBy('name');

        foreach (self::ROUTE_NAMES as $name => $uri) {
            $this->assertArrayHasKey($name, $listed);
            $this->assertSame($uri, $listed[$name]['uri']);
            $this->assertStringContainsString('GET', $listed[$name]['method']);
            $middleware = is_array($listed[$name]['middleware']) ? implode(',', $listed[$name]['middleware']) : (string) $listed[$name]['middleware'];
            $this->assertStringContainsString('auth', $middleware);
            $this->assertStringContainsString('tenant.context', $middleware);
        }
    }

    public function test_backing_api_routes_resolve_to_real_controllers_and_are_tenant_guarded(): void
    {
        $contracts = [
            '/api/tenants/alpha/sites' => SiteManagementController::class.'@index',
            '/api/v1/tenants/alpha/notifications' => EmailNotificationController::class.'@index',
            '/api/v1/tenants/alpha/email/deliveries' => EmailNotificationController::class.'@deliveries',
            '/tenants/alpha/admin/backups' => AdminOperationsController::class.'@backups',
            '/tenants/alpha/admin/logs' => AdminOperationsController::class.'@logs',
            '/tenants/alpha/admin/operations' => AdminOperationsController::class.'@operations',
            '/tenants/alpha/admin/members' => AdminOperationsController::class.'@members',
            '/tenants/alpha/admin/sessions' => AdminOperationsController::class.'@sessions',
        ];

        foreach ($contracts as $uri => $action) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertSame($action, $route->getActionName(), $uri);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('tenant.context', $middleware, $uri);
            $this->assertTrue(in_array('auth', $middleware, true) || in_array('web', $middleware, true), $uri);
        }
    }

    public function test_canonical_routes_do_not_shadow_existing_backend_json_routes(): void
    {
        $this->assertSame(
            AdminOperationsController::class.'@sessions',
            Route::getRoutes()->match(Request::create('/tenants/alpha/admin/sessions', 'GET'))->getActionName()
        );
        $this->assertSame(
            AdminOperationsController::class.'@roles',
            Route::getRoutes()->match(Request::create('/tenants/alpha/admin/roles', 'GET'))->getActionName()
        );
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

        $direct = [
            '/sites', '/notifications', '/email/history', '/module/backups', '/module/logs', '/operations',
            '/admin/users', '/account/sessions',
        ];
        foreach ($direct as $path) {
            $this->actingAs($authorized)->get('/tenants/alpha'.$path)->assertOk()->assertSee('id="app"', false);
        }

        $aliases = [
            '/admin/application-users' => '/admin/users',
            '/settings/sessions' => '/account/sessions',
            '/logs' => '/module/logs',
            '/operations/hub' => '/operations',
            '/backups' => '/module/backups',
        ];
        foreach ($aliases as $source => $target) {
            $this->actingAs($authorized)->get('/tenants/alpha'.$source)->assertRedirect('/tenants/alpha'.$target);
        }

        foreach ($direct as $path) {
            $this->actingAs($limited)->get('/tenants/limited'.$path)->assertForbidden();
        }

        $this->actingAs($authorized)->get('/tenants/beta/sites')->assertNotFound();
        $this->actingAs($limited)->get('/tenants/limited/backups')->assertForbidden();
    }

    public function test_terminal_routes_are_backed_by_live_service_responses_not_placeholder_pages(): void
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

        return $membership;
    }
}
