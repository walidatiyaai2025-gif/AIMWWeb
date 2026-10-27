<?php

namespace Tests\Feature;

use App\Http\Controllers\SecurityAuditReadController;
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

final class SettingsSecurityAuditLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-ED2F1E1572';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_source_semantics_and_laravel_control_bind_the_exact_settings_operation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/settings-security-audit-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('ApplicationPermissionCatalog.SettingsManage', $source);
        $this->assertStringContainsString('href="/admin/security-audit"', $source);
        $this->assertStringContainsString('Security audit trail', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('href="/admin/security-audit"', $control);
        $this->assertStringContainsString("hasPermission(context, 'tenant.view')", $control);
        $this->assertStringContainsString("hasPermission(context, 'settings.manage')", $control);
        $this->assertStringContainsString('<SettingsSecurityAuditLinkControl context={context} />', $app);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_destination_is_the_real_read_only_platform_admin_security_audit_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/security-audit', 'GET'));

        $this->assertSame(SecurityAuditReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.admin.security-audit', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('platform.admin', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/admin/security-audit')->assertStatus(405);
    }

    public function test_settings_and_security_audit_boundaries_fail_closed_without_weakening_authorization(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $this->membership($admin, 'alpha-security-audit', ['tenant.view', 'settings.manage']);

        $this->actingAs($admin)
            ->get('/tenants/alpha-security-audit/settings')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/admin/security-audit')
            ->assertOk()
            ->assertSee('Security Audit');

        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'member-security-audit', ['tenant.view', 'settings.manage']);

        $this->actingAs($member)
            ->get('/admin/security-audit')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/tenants/member-security-audit/settings')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'settings-security-audit-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
