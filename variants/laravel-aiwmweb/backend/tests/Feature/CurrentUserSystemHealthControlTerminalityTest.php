<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

class CurrentUserSystemHealthControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-FC900E61B8';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_canonical_operation_contract_matches_current_user_system_health_source_semantics(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('identity', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertSame('/system-health -> /system-health', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_destination_is_get_only_authenticated_tenant_route_with_exact_permissions(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/system-health', 'GET'));

        $this->assertSame('tenant.system-health', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_guest_missing_permission_and_foreign_tenant_all_fail_closed(): void
    {
        $this->get('/tenants/alpha/system-health')->assertRedirect('/login');

        $tenantOnly = User::factory()->create();
        $this->membership($tenantOnly, 'alpha', ['tenant.view']);
        $this->actingAs($tenantOnly)->get('/tenants/alpha/system-health')->assertForbidden();

        $diagnosticsOnly = User::factory()->create();
        $this->membership($diagnosticsOnly, 'diagnostics', ['diagnostics.view']);
        $this->actingAs($diagnosticsOnly)->get('/tenants/diagnostics/system-health')->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['tenant.view', 'diagnostics.view']);
        $this->actingAs($authorized)->get('/tenants/foreign/system-health')->assertNotFound();
    }

    public function test_authorized_navigation_uses_server_tenant_context_and_ignores_query_identity_overrides(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'diagnostics.view']);
        $secretSentinel = 'system-health-secret-must-never-render';
        config()->set('services.test_only.secret', $secretSentinel);

        $response = $this->actingAs($user)->get('/tenants/alpha/system-health?tenant=foreign&user_id=999&membership_id=999');

        $response->assertOk()
            ->assertSee('id="app"', false)
            ->assertDontSee($secretSentinel)
            ->assertDontSee('user_id=999')
            ->assertDontSee('membership_id=999');

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context?tenant=foreign&user_id=999')
            ->assertOk()
            ->json();

        $this->assertSame('alpha', data_get($context, 'tenant.slug'));
        $this->assertSame($user->id, data_get($context, 'user.id'));
        $this->assertContains('tenant.view', $context['permissions'] ?? []);
        $this->assertContains('diagnostics.view', $context['permissions'] ?? []);
        $this->assertNotSame('foreign', data_get($context, 'tenant.slug'));
    }

    public function test_repeated_get_is_idempotent_and_creates_no_tenant_state_or_write_surface(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'diagnostics.view']);
        $tenantId = $membership->tenant_id;
        $membershipsBefore = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $rolesBefore = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $this->actingAs($user)->get('/tenants/alpha/system-health')->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/system-health')->assertOk();

        $this->assertSame(
            $membershipsBefore,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'Read-only System Health navigation must not create tenant memberships.',
        );
        $this->assertSame(
            $rolesBefore,
            Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'Read-only System Health navigation must not create tenant roles.',
        );

        $control = (string) file_get_contents(resource_path('js/current-user-system-health-control.tsx'));
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('method:', $control);
        $this->assertStringNotContainsString('success', strtolower($control));
        $this->assertStringNotContainsString('demo', strtolower($control));
        $this->assertStringNotContainsString('sample', strtolower($control));
    }

    public function test_runtime_binding_is_server_derived_and_preserves_existing_current_user_controls(): void
    {
        $runtime = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $control = (string) file_get_contents(resource_path('js/current-user-system-health-control.tsx'));
        $provider = (string) file_get_contents(app_path('Providers/SystemHealthRouteServiceProvider.php'));

        $this->assertStringContainsString("import { CurrentUserAboutBuildControl } from './current-user-about-build-control';", $runtime);
        $this->assertStringContainsString("import { CurrentUserSystemHealthControl } from './current-user-system-health-control';", $runtime);
        $this->assertStringContainsString('<CurrentUserAboutBuildControl context={context} />', $runtime);
        $this->assertStringContainsString('<CurrentUserSystemHealthControl context={context} />', $runtime);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('context.tenants.some', $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('diagnostics.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('*')", $control);
        $this->assertStringContainsString("route.key === 'system-health'", $control);
        $this->assertStringContainsString("systemHealthRoute?.path === '/system-health'", $control);
        $this->assertStringContainsString("systemHealthRoute.permission === 'diagnostics.view'", $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/system-health')", $control);
        $this->assertStringNotContainsString('tenant=', $control);
        $this->assertStringNotContainsString('user_id', $control);
        $this->assertStringContainsString("->defaults('workspace_permissions', 'tenant.view,diagnostics.view')", $provider);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "current-user-system-health-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
