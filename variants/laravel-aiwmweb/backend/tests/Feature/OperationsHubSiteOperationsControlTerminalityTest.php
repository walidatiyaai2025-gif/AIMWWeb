<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\RouteApiAdapterController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OperationsHubSiteOperationsControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-BCAD1E3A49';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_operations_hub_history_navigation(): void
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
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/operations | /operations/hub', $operation['route_screen']);
        $this->assertSame('/site-operations -> /site-operations', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteOperationsHub.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_and_laravel_runtime_are_bound_to_the_real_history_destination(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsHub.razor'));
        $control = (string) file_get_contents(resource_path('js/operations-hub-site-operations-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString('"/site-operations"', $source);
        $this->assertStringContainsString('Site operation history', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("candidate.key === 'site-operations'", $pages);
        $this->assertStringContainsString('<OperationsHubSiteOperationsControl key={candidate.key} context={context} />', $pages);
        $this->assertStringContainsString("r('site-operations', '/site-operations'", $core);
        $this->assertStringContainsString("route.permission === 'execution.view'", $control);
        $this->assertStringContainsString("context.permissions.includes('operations.manage')", $control);
        $this->assertStringContainsString("context.permissions.includes('execution.view')", $control);
        $this->assertStringContainsString("context.api['site-operations'] === expectedApi", $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, route.path)', $control);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_host_destination_and_authoritative_api_are_tenant_guarded_reads(): void
    {
        $hub = Route::getRoutes()->match(Request::create('/tenants/alpha/operations', 'GET'));
        $history = Route::getRoutes()->match(Request::create('/tenants/alpha/site-operations', 'GET'));
        $api = Route::getRoutes()->match(Request::create('/tenants/alpha/route-api/site-operations', 'GET'));

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $hub->getActionName());
        $this->assertSame('canonical.workspace.operations', $hub->getName());
        $this->assertSame('operations.manage,execution.view', $hub->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $hub->gatherMiddleware());
        $this->assertContains('tenant.context', $hub->gatherMiddleware());

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $history->getActionName());
        $this->assertSame('canonical.workspace.site-operations', $history->getName());
        $this->assertSame('execution.view', $history->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $history->gatherMiddleware());
        $this->assertContains('tenant.context', $history->gatherMiddleware());

        $this->assertSame(RouteApiAdapterController::class.'@siteOperations', ltrim($api->getActionName(), '\\'));
        $this->assertSame('canonical.api.site-operations', $api->getName());
        $this->assertContains('auth', $api->gatherMiddleware());
        $this->assertContains('tenant.context', $api->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $history->methods());
        $this->assertSame(['GET', 'HEAD'], $api->methods());
    }

    public function test_authorized_member_can_open_hub_destination_and_real_api_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'operations.manage', 'execution.view']);
        $tenantId = $membership->tenant_id;

        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/operations')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/site-operations')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('api.site-operations', '/tenants/alpha/route-api/site-operations');

        $this->actingAs($user)
            ->getJson('/tenants/alpha/route-api/site-operations')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertSame($membershipCount, TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
        $this->assertSame($roleCount, Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());

        $this->actingAs($user)->post('/tenants/alpha/site-operations')->assertStatus(405);
    }

    public function test_guest_missing_host_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/operations')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['execution.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/operations')
            ->assertForbidden();

        $this->actingAs($limited)
            ->get('/tenants/limited/site-operations')
            ->assertOk();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['operations.manage', 'execution.view']);
        Tenant::query()->create(['slug' => 'foreign', 'name' => 'Foreign']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign/operations')
            ->assertNotFound();

        $this->actingAs($authorized)
            ->get('/tenants/foreign/site-operations')
            ->assertNotFound();

        $this->actingAs($authorized)
            ->getJson('/tenants/foreign/route-api/site-operations')
            ->assertNotFound();
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
        $role = Role::query()->create([
            'name' => "operations-hub-site-operations-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}

// exact-head gate retrigger after tenant.view fixture correction
