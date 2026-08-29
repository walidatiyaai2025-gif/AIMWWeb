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

class AccountProfileRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-FB7F9189C0';

    public function test_exact_canonical_operation_is_the_pending_account_profile_route(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/account/profile', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountProfile.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_guarded_and_not_the_tenant_spa_catch_all(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/account/profile', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.account-profile', $route->getName());
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_user_renders_workspace_and_profile_api_authoritatively_rereads_persisted_membership(): void
    {
        $user = User::factory()->create([
            'name' => 'Alpha Owner',
            'email' => 'alpha-owner@example.test',
        ]);
        $roleName = 'Owner-alpha-'.$user->id;
        $this->membership($user, 'alpha', ['tenant.view'], 'Owner');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/account/profile')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->json();

        $this->assertSame('alpha', data_get($context, 'tenant.slug'));
        $this->assertSame(
            '/tenants/alpha/route-api/account-profile',
            $context['api']['account.profile'] ?? null,
        );

        $this->actingAs($user)
            ->getJson('/tenants/alpha/route-api/account-profile')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.name', 'Alpha Owner')
            ->assertJsonPath('data.0.email', 'alpha-owner@example.test')
            ->assertJsonPath('data.0.membership_status', 'active')
            ->assertJsonPath('data.0.roles.0', $roleName);
    }

    public function test_guest_missing_permission_and_cross_tenant_direct_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/account/profile')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', [], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/account/profile')->assertForbidden();
        $this->actingAs($limited)->getJson('/tenants/limited/route-api/account-profile')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Role');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Role');

        $this->actingAs($alpha)->get('/tenants/beta/account/profile')->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/beta/route-api/account-profile')->assertNotFound();
    }

    public function test_route_has_no_caller_supplied_user_or_membership_id_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/account/profile', 'GET'));

        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertStringNotContainsString('{user}', $route->uri());
        $this->assertStringNotContainsString('{membership}', $route->uri());
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
