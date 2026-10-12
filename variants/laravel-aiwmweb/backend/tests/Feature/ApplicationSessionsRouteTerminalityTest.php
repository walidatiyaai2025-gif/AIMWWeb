<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApplicationSessionsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6B051BD8C0';

    public function test_source_and_laravel_route_bind_admin_sessions_render_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationSessions.razor'),
        );

        $this->assertStringContainsString('@page "/admin/sessions"', $source);
        $this->assertStringContainsString(
            'Authorize(Policy = ApplicationPermissionCatalog.UsersManage)',
            $source,
        );
        $this->assertStringContainsString('Session Management', $source);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/admin/sessions', 'GET'),
        );

        $this->assertSame(
            AdminOperationsController::class.'@sessions',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.admin-sessions', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_admin_sessions_route_renders_workspace_and_fails_closed_without_users_manage(): void
    {
        $this->get('/tenants/alpha/admin/sessions')->assertRedirect('/login');

        $viewer = User::factory()->create();
        $this->membership($viewer, 'viewer', ['tenant.view']);
        $this->actingAs($viewer)
            ->get('/tenants/viewer/admin/sessions')
            ->assertForbidden();

        $admin = User::factory()->create();
        $this->membership($admin, 'alpha', ['tenant.view', 'users.manage']);
        $this->withoutVite();
        $this->actingAs($admin)
            ->get('/tenants/alpha/admin/sessions')
            ->assertOk()
            ->assertSee('<div id="app"', false);
    }

    public function test_application_sessions_api_reads_only_current_tenant_users_from_real_session_storage(): void
    {
        $admin = User::factory()->create(['name' => 'Alpha Admin', 'email' => 'admin@alpha.test']);
        $alphaAdmin = $this->membership($admin, 'alpha', ['tenant.view', 'users.manage'], 'Owner');

        $member = User::factory()->create(['name' => 'Alpha Member', 'email' => 'member@alpha.test']);
        $this->membershipExisting($member, $alphaAdmin->tenant, ['tenant.view'], 'Editor');

        $betaUser = User::factory()->create(['name' => 'Beta User', 'email' => 'user@beta.test']);
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'users.manage'], 'Owner');

        DB::table('sessions')->insert([
            [
                'id' => 'alpha-admin-session',
                'user_id' => $admin->id,
                'ip_address' => '10.0.0.1',
                'user_agent' => 'Alpha Browser',
                'payload' => '',
                'last_activity' => time(),
            ],
            [
                'id' => 'alpha-member-session',
                'user_id' => $member->id,
                'ip_address' => '10.0.0.2',
                'user_agent' => 'Member Browser',
                'payload' => '',
                'last_activity' => time() - 60,
            ],
            [
                'id' => 'beta-session',
                'user_id' => $betaUser->id,
                'ip_address' => '10.0.0.3',
                'user_agent' => 'Beta Browser',
                'payload' => '',
                'last_activity' => time(),
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/admin/sessions')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains('alpha-admin-session', $ids);
        $this->assertContains('alpha-member-session', $ids);
        $this->assertNotContains('beta-session', $ids);

        $memberRow = collect($response->json('data'))->firstWhere('id', 'alpha-member-session');
        $this->assertSame('Alpha Member', $memberRow['user_name'] ?? null);
        $this->assertStringContainsString('Editor', (string) ($memberRow['role'] ?? ''));
        $this->assertSame('10.0.0.2', $memberRow['ip_address'] ?? null);
        $this->assertArrayHasKey('expires_at', $memberRow);

        $this->actingAs($admin)
            ->getJson('/api/tenants/beta/admin/sessions')
            ->assertNotFound();

        app(TenantContext::class)->forget();
    }

    public function test_frontend_contract_uses_distinct_admin_and_my_sessions_apis(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString(
            "'sessions' => \"/api/tenants/{\$tenant}/sessions\"",
            $routes,
        );
        $this->assertStringContainsString(
            "'application-sessions' => \"/api/tenants/{\$tenant}/admin/sessions\"",
            $routes,
        );
        $this->assertStringContainsString(
            "r('application-sessions', '/admin/sessions'",
            $core,
        );
        $this->assertStringContainsString("apiKey: 'application-sessions'", $core);
        $this->assertStringContainsString("permission: 'users.manage'", $core);
    }

    private function membership(
        User $user,
        string $slug,
        array $permissions,
        string $roleName = 'Manager',
    ): TenantMembership {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);

        return $this->membershipExisting($user, $tenant, $permissions, $roleName);
    }

    private function membershipExisting(
        User $user,
        Tenant $tenant,
        array $permissions,
        string $roleName,
    ): TenantMembership {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "{$roleName}-{$user->id}"]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh(['tenant', 'user', 'roles']);
    }
}
