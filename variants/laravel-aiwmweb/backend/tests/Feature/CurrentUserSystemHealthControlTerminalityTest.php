<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentUserSystemHealthControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-FC900E61B8';

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

    public function test_authorized_user_can_open_real_system_health_workspace_and_context_exposes_required_permissions(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'diagnostics.view'], 'Diagnostics');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/system-health')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->json();

        $this->assertSame('alpha', data_get($context, 'tenant.slug'));
        $this->assertContains('tenant.view', $context['permissions'] ?? []);
        $this->assertContains('diagnostics.view', $context['permissions'] ?? []);
    }

    public function test_guest_missing_permission_and_cross_tenant_system_health_navigation_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/system-health')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view'], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/system-health')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'diagnostics.view'], 'Alpha Diagnostics');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'diagnostics.view'], 'Beta Diagnostics');

        $this->actingAs($alpha)->get('/tenants/beta/system-health')->assertNotFound();
    }

    public function test_runtime_binding_is_tenant_derived_and_server_route_enforces_same_permission_contract(): void
    {
        $runtime = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $control = (string) file_get_contents(resource_path('js/current-user-system-health-control.tsx'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("import { CurrentUserSystemHealthControl } from './current-user-system-health-control';", $runtime);
        $this->assertStringContainsString('<CurrentUserSystemHealthControl context={context} />', $runtime);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('diagnostics.view')", $control);
        $this->assertStringContainsString("route.key === 'system-health'", $control);
        $this->assertStringContainsString("systemHealthRoute?.path === '/system-health'", $control);
        $this->assertStringContainsString("systemHealthRoute.permission === 'diagnostics.view'", $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/system-health')", $control);
        $this->assertStringNotContainsString('tenant=', $control);
        $this->assertStringContainsString("Route::get('/system-health', 'show')->defaults('workspace_permissions', 'tenant.view,diagnostics.view')", $routes);
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
