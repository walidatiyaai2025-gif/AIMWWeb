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

final class MySessionsAdminLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-2CDC790742';

    public function test_source_semantics_and_production_control_bind_the_exact_operation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/MySessions.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/my-sessions-admin-link-control.tsx'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('@page "/settings/sessions"', $source);
        $this->assertStringContainsString(
            'CurrentUser.HasPermission(ApplicationPermissionCatalog.UsersManage)',
            $source,
        );
        $this->assertStringContainsString('href="/admin/sessions"', $source);
        $this->assertStringContainsString('Manage all sessions', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/admin/sessions')", $control);
        $this->assertStringContainsString("context.permissions.includes('members.manage')", $control);
        $this->assertStringContainsString("route.key === 'sessions'", $app);
        $this->assertStringContainsString('<MySessionsAdminLinkControl context={context} />', $app);
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
            ->assertSee('Application sessions')
            ->assertSee('data-canonical-operation="AIMW-CONT-6B051BD8C0"', false);

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/admin/sessions")
            ->assertNotFound();

        $this->actingAs($limited->user)
            ->get('/tenants/limited/admin/sessions')
            ->assertForbidden();
    }

    public function test_source_workspace_alias_remains_real_and_tenant_scoped(): void
    {
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

        $this->actingAs($authorized->user)
            ->get("/tenants/{$alpha->slug}/settings/sessions")
            ->assertRedirect("/tenants/{$alpha->slug}/account/sessions");

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/settings/sessions")
            ->assertNotFound();
    }

    private function tenantWithUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => "{$slug}-my-sessions-admin-link@example.test"]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->setRelation('user', $user);

        $role = Role::query()->create(['name' => "my-sessions-admin-link-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $membership];
    }
}
