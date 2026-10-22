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

class SystemHealthRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6F699A5C14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_the_system_health_route(): void
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
        $this->assertSame('/system-health', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SystemHealth.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_get_only_and_enforces_both_permissions(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/system-health', 'GET'));

        $this->assertSame('tenant.system-health', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/tenants/alpha/system-health')->assertStatus(405);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
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

    public function test_authorized_reads_are_tenant_derived_idempotent_and_secret_free(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'diagnostics.view']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $secretSentinel = 'system-health-route-secret-must-never-render';
        config()->set('services.test_only.secret', $secretSentinel);

        $first = $this->actingAs($user)->get(
            '/tenants/alpha/system-health?tenant=foreign&user_id=999&membership_id=999&site=999',
        );
        $second = $this->actingAs($user)->get('/tenants/alpha/system-health');

        $first->assertOk()
            ->assertSee('id="app"', false)
            ->assertDontSee($secretSentinel)
            ->assertDontSee('user_id=999')
            ->assertDontSee('membership_id=999');
        $second->assertOk();

        $this->assertSame(
            $membershipCount,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame(
            $roleCount,
            Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );

        $route = Route::getRoutes()->getByName('tenant.system-health');
        $this->assertNotNull($route);
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertNotContains('user', $route->parameterNames());
        $this->assertNotContains('membership', $route->parameterNames());
        $this->assertNotContains('site', $route->parameterNames());
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
        $role = Role::query()->create(['name' => "system-health-route-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
