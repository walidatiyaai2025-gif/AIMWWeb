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

final class SettingsAdminSessionsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-4A64732FC6';

    public function test_source_semantics_and_production_control_bind_the_exact_operation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/settings-admin-sessions-link-control.tsx'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('ApplicationPermissionCatalog.UsersManage', $source);
        $this->assertStringContainsString('href="/admin/sessions"', $source);
        $this->assertStringContainsString('Session management', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/admin/sessions')", $control);
        $this->assertStringContainsString("context.permissions.includes('members.manage')", $control);
        $this->assertStringContainsString("route.key === 'settings'", $app);
        $this->assertStringContainsString('<SettingsAdminSessionsLinkControl context={context} />', $app);
    }

    public function test_real_admin_sessions_destination_is_guarded_and_tenant_isolated(): void
    {
        $this->get('/tenants/alpha/admin/sessions')->assertRedirect('/login');

        [$alpha, $authorized] = $this->tenantWithUser('alpha', [
            'tenant.view',
            'sessions.manage',
            'sessions.view',
            'members.manage',
        ]);
        [$beta] = $this->tenantWithUser('beta', [
            'tenant.view',
            'sessions.manage',
            'sessions.view',
            'members.manage',
        ]);
        [, $limited] = $this->tenantWithUser('limited', [
            'tenant.view',
            'sessions.manage',
            'sessions.view',
        ]);

        $this->actingAs($authorized->user)
            ->get("/tenants/{$alpha->slug}/admin/sessions")
            ->assertOk()
            ->assertSee('Application sessions');

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/admin/sessions")
            ->assertNotFound();

        $this->actingAs($limited->user)
            ->get('/tenants/limited/admin/sessions')
            ->assertForbidden();
    }

    private function tenantWithUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => "{$slug}-settings-admin-sessions@example.test"]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->setRelation('user', $user);

        $role = Role::query()->create(['name' => "settings-admin-sessions-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $membership];
    }
}
