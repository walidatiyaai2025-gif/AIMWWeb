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

class CurrentUserSettingsControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-A1064E5A5E';

    public function test_exact_canonical_operation_is_the_pending_current_user_settings_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('identity', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertSame('/settings -> /settings', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_authorized_current_user_can_follow_the_control_to_the_real_tenant_settings_workspace(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view'], 'Owner');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/settings')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->json();

        $this->assertSame('alpha', data_get($context, 'tenant.slug'));
        $this->assertContains('tenant.view', $context['permissions'] ?? []);

        $routes = (string) file_get_contents(resource_path('js/core.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString("r('settings', '/settings', 'system'", $routes);
        $this->assertStringContainsString("{ permission: 'tenant.view', kind: 'settings' }", $routes);
        $this->assertStringContainsString("if (route.key === 'settings')", $app);
    }

    public function test_guest_missing_permission_and_cross_tenant_settings_navigation_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/settings')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', [], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/settings')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Role');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Role');

        $this->actingAs($alpha)->get('/tenants/beta/settings')->assertNotFound();
    }

    public function test_runtime_binding_uses_exact_operation_marker_and_authoritative_tenant_route(): void
    {
        $runtime = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $control = (string) file_get_contents(resource_path('js/current-user-settings-control.tsx'));

        $this->assertStringContainsString("import { CurrentUserSettingsControl } from './current-user-settings-control';", $runtime);
        $this->assertStringContainsString('<CurrentUserSettingsControl context={context} />', $runtime);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("route.key === 'settings'", $control);
        $this->assertStringContainsString("settingsRoute?.path === '/settings'", $control);
        $this->assertStringContainsString("settingsRoute.permission === 'tenant.view'", $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/settings')", $control);
        $this->assertStringContainsString("document.querySelector<HTMLElement>('.topbar-actions')", $control);
        $this->assertStringNotContainsString('tenant=', $control);
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
