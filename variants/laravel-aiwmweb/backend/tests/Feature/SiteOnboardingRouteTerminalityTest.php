<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

final class SiteOnboardingRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-9F0D652C67';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_the_site_onboarding_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/onboarding', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOnboarding.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);

        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOnboarding.razor'),
        );
        $this->assertStringContainsString('@page "/sites/onboarding"', $source);
        $this->assertStringContainsString('@page "/sites/connect"', $source);
    }

    public function test_onboarding_route_is_explicit_guarded_read_only_and_independently_operation_linked(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/onboarding', 'GET'));

        $this->assertSame('canonical.site.onboarding', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('AIMW-SITE-E3EA44AD3F', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_route_operation_id'] ?? null);
        $this->assertSame('tenant.view,sites.manage', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $connect = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/connect', 'GET'));
        $this->assertSame('AIMW-SITE-E3EA44AD3F', $connect->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('AIMW-CONT-BF5F72F4F9', $connect->defaults['canonical_route_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $connect->defaults['canonical_route_operation_id'] ?? null);

        $this->post('/tenants/alpha/sites/onboarding')->assertStatus(405);
    }

    public function test_authorized_route_renders_the_real_onboarding_workspace_without_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);

        $before = Site::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/onboarding')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($before, Site::query()->withoutGlobalScopes()->count());

        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-onboarding-save-test-sync-control.tsx'));

        $this->assertStringContainsString(
            '<Route path="sites/onboarding" element={<RouteElement route={workspaceRoutes.find((route) => route.key === \'site-connect\')!} />} />',
            $app,
        );
        $this->assertStringContainsString(
            "if (route.key === 'site-connect') return <SiteOnboardingSaveTestSyncControl context={context} />;",
            $app,
        );
        $this->assertStringContainsString('canonicalSiteOnboardingEndpoint(context.api.sites)', $control);
        $this->assertStringContainsString('validateOnboardingStep', $control);
        $this->assertStringContainsString('useMutation', $control);
        $this->assertStringContainsString('result.site.connection_status', $control);
        $this->assertStringContainsString('result.sync.status', $control);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/sites/onboarding')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/onboarding')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'sites.manage']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($authorized)
            ->get('/tenants/beta/sites/onboarding')
            ->assertNotFound();
    }

    public function test_query_identity_cannot_override_tenant_context(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $siteCount = Site::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/onboarding?tenant=foreign&site=999&user_id=999&membership_id=999')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame(
            $membershipCount,
            TenantMembership::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame($siteCount, Site::query()->withoutGlobalScopes()->count());
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
        $role = Role::query()->create(['name' => "site-onboarding-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
