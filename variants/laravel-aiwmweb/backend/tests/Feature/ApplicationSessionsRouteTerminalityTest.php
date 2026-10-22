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

    public function test_source_contract_and_route_binding_match_admin_sessions_open_render_operation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationSessions.razor'),
        );

        $this->assertStringContainsString('@page "/admin/sessions"', $source);
        $this->assertStringContainsString('Authorize(Policy = ApplicationPermissionCatalog.UsersManage)', $source);
        $this->assertStringContainsString('Service.ListAllAsync(_includeInactive)', $source);
        $this->assertStringContainsString('Session inventory', $source);
        $this->assertStringContainsString('Active accounts', $source);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/admin/sessions', 'GET'),
        );

        $this->assertSame(AdminOperationsController::class.'@sessions', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.application-sessions', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        [$alpha, $owner] = $this->tenantWithUser('alpha', ['tenant.view', 'members.manage'], 'owner');
        [$beta] = $this->tenantWithUser('beta', ['tenant.view', 'members.manage'], 'owner');
        $limited = $this->member($alpha, 'limited@example.test', ['tenant.view']);

        $this->get('/tenants/alpha/admin/sessions')->assertRedirect('/login');

        $this->actingAs($limited->user)
            ->get('/tenants/alpha/admin/sessions')
            ->assertForbidden();

        $this->actingAs($owner->user)
            ->get("/tenants/{$beta->slug}/admin/sessions")
            ->assertNotFound();
    }

    public function test_route_renders_only_active_sessions_for_members_of_current_tenant(): void
    {
        [$alpha, $owner] = $this->tenantWithUser('alpha', ['tenant.view', 'members.manage'], 'owner');
        $member = $this->member($alpha, 'member@example.test', ['tenant.view'], 'editor');
        [$beta, $foreign] = $this->tenantWithUser('beta', ['tenant.view', 'members.manage'], 'owner');

        $now = time();
        DB::table('sessions')->insert([
            $this->sessionRow('alpha-owner', $owner->user_id, $now - 30, '10.0.0.1', 'Owner Browser'),
            $this->sessionRow('alpha-member', $member->user_id, $now - 60, '10.0.0.2', 'Member Browser'),
            $this->sessionRow('alpha-expired', $member->user_id, $now - 10000, '10.0.0.9', 'Expired Browser'),
            $this->sessionRow('beta-foreign', $foreign->user_id, $now - 20, '10.1.0.1', 'Foreign Browser'),
        ]);

        $before = DB::table('sessions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $response = $this->actingAs($owner->user)
            ->get('/tenants/alpha/admin/sessions')
            ->assertOk()
            ->assertSee('Application sessions')
            ->assertSee('Session inventory')
            ->assertSee('Active accounts')
            ->assertSee($owner->user->name)
            ->assertSee($member->user->name)
            ->assertSee('10.0.0.1')
            ->assertSee('10.0.0.2')
            ->assertSee('Owner Browser')
            ->assertSee('Member Browser')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('10.0.0.9')
            ->assertDontSee('Expired Browser')
            ->assertDontSee('10.1.0.1')
            ->assertDontSee('Foreign Browser');

        $after = DB::table('sessions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame($before, $after);
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
    }

    public function test_existing_json_sessions_contract_remains_available_for_sessions_manage_clients(): void
    {
        [$alpha, $member] = $this->tenantWithUser('alpha', ['tenant.view', 'sessions.manage'], 'member');
        DB::table('sessions')->insert(
            $this->sessionRow('json-session', $member->user_id, time() - 10, '127.0.0.1', 'JSON Browser'),
        );

        $this->actingAs($member->user)
            ->getJson('/tenants/alpha/admin/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'json-session')
            ->assertJsonPath('data.0.ip_address', '127.0.0.1');

        $this->assertSame('alpha', $alpha->slug);
    }

    public function test_empty_state_is_truthful_and_get_has_no_side_effect(): void
    {
        [, $owner] = $this->tenantWithUser('alpha', ['tenant.view', 'members.manage'], 'owner');

        $this->assertDatabaseCount('sessions', 0);

        $this->actingAs($owner->user)
            ->get('/tenants/alpha/admin/sessions')
            ->assertOk()
            ->assertSee('No accounts currently have active sessions.')
            ->assertSee('No active sessions.');

        $this->assertDatabaseCount('sessions', 0);
    }

    private function tenantWithUser(string $slug, array $permissions, string $role): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $membership = $this->member($tenant, "{$slug}-owner@example.test", $permissions, $role);

        return [$tenant, $membership];
    }

    private function member(
        Tenant $tenant,
        string $email,
        array $permissions,
        string $roleName = 'member',
    ): TenantMembership {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => $email]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "{$roleName}-{$tenant->slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return $membership;
    }

    private function sessionRow(
        string $id,
        int $userId,
        int $lastActivity,
        string $ip,
        string $agent,
    ): array {
        return [
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $agent,
            'payload' => '',
            'last_activity' => $lastActivity,
        ];
    }
}
