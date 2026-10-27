<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\WordPressUsersController;
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

final class GlobalUsersWorkspaceRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-C3EC665880';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_global_users_workspace_route(): void
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
        $this->assertSame('/module/users', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalUsersWorkspace.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_route_is_explicit_get_only_site_bound_and_operation_linked(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/users', 'GET'));

        $this->assertSame('canonical.workspace.wordpress-users', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSiteBound', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->post('/tenants/alpha/module/users')->assertStatus(405);
    }

    public function test_authorized_route_binds_real_site_and_advertises_authoritative_users_read(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Users');

        $this->actingAs($user)
            ->get('/tenants/alpha/module/users?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSessionHas('canonical_site_id', $site->id);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('api.wp-users', '/api/v1/tenants/alpha/sites/'.$site->id.'/users');

        $apiRoute = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/'.$site->id.'/users', 'GET'),
        );
        $this->assertSame(WordPressUsersController::class.'@index', ltrim($apiRoute->getActionName(), '\\'));
        $this->assertSame('api.v1.wordpress-users.index', $apiRoute->getName());
        $this->assertSame(['GET', 'HEAD'], $apiRoute->methods());

        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString("r('wp-users', '/module/users'", $core);
        $this->assertStringContainsString("connectorScope: 'users.read'", $core);
        $this->assertStringContainsString("if (route.key === 'wp-users')", $pages);
        $this->assertStringContainsString('<ResourceContent context={context} route={route} />', $pages);
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $this->get('/tenants/alpha/module/users')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->get('/tenants/limited/module/users?site='.$limitedSite->id)
            ->assertForbidden();

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/module/users?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/module/users?site='.$alphaSite->id)
            ->assertNotFound();
    }

    public function test_site_context_is_deterministic_and_route_does_not_mutate_tenant_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $first = $this->site($membership, 'First Site');
        $this->site($membership, 'Second Site');

        $tenantId = $membership->tenant_id;
        $siteCount = Site::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/module/users')
            ->assertStatus(409);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/users?site=not-a-site')
            ->assertStatus(422);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/users?site='.$first->id.'&tenant=foreign&user_id=999')
            ->assertOk();

        $this->assertSame($siteCount, Site::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
        $this->assertSame($membershipCount, TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
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
        $role = Role::query()->create(['name' => "global-users-route-{$slug}-{$user->id}"]);

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

        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
        } finally {
            $context->forget();
        }
    }
}
