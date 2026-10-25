<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\DashboardReadController;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\CanonicalHomeRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class HomeRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-519B93F503';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_home_open_render_route(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Home.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_frontend_and_explicit_routes_preserve_real_dashboard_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Home.razor'),
        );
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('@page "/"', $source);
        $this->assertStringContainsString('<PageTitle>@L["Dashboard"]</PageTitle>', $source);
        $this->assertStringContainsString('Loading dashboard', $source);
        $this->assertStringContainsString('Dashboard could not be loaded', $source);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha', 'GET'));
        $this->assertSame('canonical.workspace.home', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $api = Route::getRoutes()->match(Request::create('/tenants/alpha/route-api/dashboard', 'GET'));
        $this->assertSame('canonical.api.dashboard', $api->getName());
        $this->assertSame(DashboardReadController::class, ltrim($api->getActionName(), '\\'));
        $this->assertTrue(method_exists(DashboardReadController::class, '__invoke'));
        $this->assertSame(self::OPERATION_ID, $api->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $api->gatherMiddleware());
        $this->assertContains('tenant.context', $api->gatherMiddleware());

        $this->assertSame(self::OPERATION_ID, CanonicalHomeRouteServiceProvider::OPERATION_ID);
        $this->assertSame(self::OPERATION_ID, DashboardReadController::OPERATION_ID);
        $this->assertStringContainsString("r('dashboard', '/',", $core);
        $this->assertStringContainsString("kind: 'dashboard'", $core);
        $this->assertStringContainsString('function DashboardContent', $pages);
        $this->assertStringContainsString('if (query.isLoading) return <LoadingState />;', $pages);
        $this->assertStringContainsString('if (query.error) return <QueryError', $pages);
        $this->assertStringContainsString('No live metrics returned', $pages);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha')->assertRedirect('/login');
        $this->get('/tenants/alpha/route-api/dashboard')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', []);

        $this->actingAs($limited)->get('/tenants/alpha')->assertForbidden();
        $this->actingAs($limited)->getJson('/tenants/alpha/route-api/dashboard')->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['tenant.view']);
        $this->actingAs($authorized)->get('/tenants/foreign')->assertNotFound();
        $this->actingAs($authorized)->getJson('/tenants/foreign/route-api/dashboard')->assertNotFound();
    }

    public function test_authorized_route_renders_and_dashboard_api_reads_only_active_tenant_data(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view']);
        $this->seedDashboardData($alphaMembership, 'alpha', 1);

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view']);
        $this->seedDashboardData($betaMembership, 'beta', 3);

        $before = [
            'sites' => DB::table('sites')->count(),
            'content_items' => DB::table('content_items')->count(),
            'media_items' => DB::table('media_items')->count(),
            'executions' => DB::table('executions')->count(),
        ];

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('api.dashboard', '/tenants/alpha/route-api/dashboard');

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/route-api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.sites.total', 1)
            ->assertJsonPath('summary.sites.connected', 1)
            ->assertJsonPath('summary.content.posts', 1)
            ->assertJsonPath('summary.content.pages', 1)
            ->assertJsonPath('summary.content.media', 1)
            ->assertJsonPath('summary.jobs.active', 0)
            ->assertJsonPath('summary.jobs.failed', 0)
            ->assertJsonCount(5, 'metrics')
            ->assertJsonPath('metrics.0.key', 'sites')
            ->assertJsonPath('metrics.0.value', 1)
            ->assertJsonPath('metrics.1.value', 2)
            ->assertJsonPath('metrics.2.value', 1);

        $this->assertSame($before['sites'], DB::table('sites')->count());
        $this->assertSame($before['content_items'], DB::table('content_items')->count());
        $this->assertSame($before['media_items'], DB::table('media_items')->count());
        $this->assertSame($before['executions'], DB::table('executions')->count());
    }

    private function seedDashboardData(TenantMembership $membership, string $prefix, int $siteCount): void
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        for ($index = 1; $index <= $siteCount; $index++) {
            $site = Site::query()->create([
                'name' => ucfirst($prefix)." Site {$index}",
                'url' => "https://{$prefix}-{$index}.test",
                'status' => 'active',
                'connection_status' => 'connected',
                'last_sync_at' => now()->subMinutes($index),
            ]);

            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $index * 10 + 1,
                'type' => 'post',
                'title' => ucfirst($prefix)." post {$index}",
                'status' => 'publish',
            ]);
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $index * 10 + 2,
                'type' => 'page',
                'title' => ucfirst($prefix)." page {$index}",
                'status' => 'publish',
            ]);
            MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $index * 10 + 3,
                'title' => ucfirst($prefix)." media {$index}",
                'source_url' => "https://{$prefix}-{$index}.test/media.jpg",
            ]);
        }

        $context->forget();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "home-route-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh(['tenant', 'user']);
    }
}
