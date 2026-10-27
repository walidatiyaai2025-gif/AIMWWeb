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

class CurrentUserAccountProfileControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-9B043B1FAE';

    public function test_exact_canonical_operation_is_the_pending_current_user_profile_control(): void
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
        $this->assertSame('/account/profile -> /account/profile', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_destination_reuses_the_real_guarded_account_profile_workspace(): void
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

    public function test_authorized_current_user_can_follow_the_control_to_authoritative_profile_state(): void
    {
        $user = User::factory()->create([
            'name' => 'Alpha Owner',
            'email' => 'alpha-owner@example.test',
        ]);
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
            ->assertJsonPath('data.0.membership_status', 'active');
    }

    public function test_guest_missing_permission_and_cross_tenant_profile_navigation_fail_closed(): void
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

    public function test_runtime_binding_uses_exact_operation_marker_and_authoritative_tenant_contract(): void
    {
        $runtime = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $control = (string) file_get_contents(resource_path('js/current-user-account-profile-control.tsx'));

        $this->assertStringContainsString("import { CurrentUserAccountProfileControl } from './current-user-account-profile-control';", $runtime);
        $this->assertStringContainsString('<CurrentUserAccountProfileControl context={context} />', $runtime);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.api['account.profile'] === expectedProfileApi", $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/account/profile')", $control);
        $this->assertStringContainsString("document.querySelector<HTMLElement>('.topbar-actions')", $control);
        $this->assertStringNotContainsString('{user}', $control);
        $this->assertStringNotContainsString('{membership}', $control);
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
