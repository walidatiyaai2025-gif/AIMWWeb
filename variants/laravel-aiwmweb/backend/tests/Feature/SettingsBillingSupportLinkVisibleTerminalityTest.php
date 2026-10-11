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

final class SettingsBillingSupportLinkVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-81D0940A0A';

    public function test_exact_canonical_settings_billing_support_control_is_adapted(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/settings', $operation['route_screen']);
        $this->assertSame('/admin/billing-support -> /admin/billing-support', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/Settings.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'));
        $frontend = (string) file_get_contents(resource_path('js/settings-billing-support-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('ApplicationPermissionCatalog.SettingsManage', $source);
        $this->assertStringContainsString('href="/admin/billing-support"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("context.permissions.includes('settings.manage')", $frontend);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/admin/billing-support')", $frontend);
        $this->assertStringContainsString("import { SettingsBillingSupportLinkControl } from './settings-billing-support-link-control';", $app);
        $this->assertStringContainsString('<SettingsBillingSupportLinkControl context={context} />', $app);
    }

    public function test_destination_keeps_tenant_context_and_platform_admin_boundary(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/billing-support', 'GET'));

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.admin-billing-support', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertContains('platform.admin', $route->gatherMiddleware());
    }

    public function test_platform_admin_can_open_owned_tenant_and_foreign_or_non_admin_access_fails_closed(): void
    {
        $this->withoutVite();

        $alphaAdmin = User::factory()->create(['platform_admin' => true]);
        $this->membership($alphaAdmin, 'alpha', ['tenant.view', 'settings.manage']);

        $this->actingAs($alphaAdmin)
            ->get('/tenants/alpha/admin/billing-support')
            ->assertOk();

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $this->membership($betaAdmin, 'beta', ['tenant.view', 'settings.manage']);
        $this->actingAs($betaAdmin)
            ->get('/tenants/alpha/admin/billing-support')
            ->assertNotFound();

        $alphaManager = User::factory()->create(['platform_admin' => false]);
        $this->membership($alphaManager, 'alpha', ['tenant.view', 'settings.manage']);
        $this->actingAs($alphaManager)
            ->get('/tenants/alpha/admin/billing-support')
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "settings-billing-support-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
