<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\RouteApiAdapterController;
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

final class RoutesOpenMyAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-03760967D1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_authenticated_denial_with_profile_access_renders_only_the_active_tenant_account_control(): void
    {
        $user = User::factory()->create([
            'name' => 'Alpha Account User',
            'email' => 'alpha-account@example.test',
        ]);
        $this->membership($user, 'alpha', ['tenant.view'], 'Alpha Account Role');

        $denied = $this->actingAs($user)
            ->get('/tenants/alpha/account/billing?tenant=beta&user_id=999999&membership_id=999999')
            ->assertForbidden()
            ->assertSee('Access denied')
            ->assertSee('Open my account')
            ->assertSee('href="/tenants/alpha/account/profile"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('href="/tenants/beta/account/profile"', false);

        $this->assertStringNotContainsString('999999', $denied->getContent());

        $this->actingAs($user)
            ->get('/tenants/alpha/account/profile')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_public_access_denied_and_json_denials_never_expose_the_profile_control(): void
    {
        $this->get('/access-denied')
            ->assertOk()
            ->assertSee('Access denied')
            ->assertDontSee(self::OPERATION_ID)
            ->assertDontSee('Open my account');

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view'], 'JSON Denial Role');

        $this->actingAs($user)
            ->getJson('/tenants/alpha/account/billing?tenant=beta&user_id=999999')
            ->assertForbidden()
            ->assertDontSee(self::OPERATION_ID)
            ->assertDontSee('Open my account')
            ->assertDontSee('/tenants/alpha/account/profile');
    }

    public function test_guest_missing_tenant_view_and_foreign_tenant_attempts_fail_closed_without_the_control(): void
    {
        $this->get('/tenants/alpha/account/billing')
            ->assertRedirect('/login')
            ->assertDontSee(self::OPERATION_ID);

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', [], 'Limited Role');
        $this->actingAs($limited)
            ->get('/tenants/limited/account/billing')
            ->assertForbidden()
            ->assertDontSee(self::OPERATION_ID)
            ->assertDontSee('Open my account');

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Role');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Role');

        $this->actingAs($alpha)
            ->get('/tenants/beta/account/billing')
            ->assertNotFound()
            ->assertDontSee(self::OPERATION_ID);

        $this->actingAs($alpha)
            ->getJson('/tenants/beta/route-api/account-profile')
            ->assertNotFound();
    }

    public function test_profile_destination_and_authoritative_read_are_explicit_guarded_get_only_and_direct_id_free(): void
    {
        $profile = Route::getRoutes()->match(Request::create('/tenants/alpha/account/profile', 'GET'));
        $api = Route::getRoutes()->match(Request::create('/tenants/alpha/route-api/account-profile', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($profile->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.account-profile', $profile->getName());
        $this->assertSame('tenant.view', $profile->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['tenant'], $profile->parameterNames());
        $this->assertContains('auth', $profile->gatherMiddleware());
        $this->assertContains('tenant.context', $profile->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $profile->methods());

        $this->assertSame(
            RouteApiAdapterController::class.'@accountProfile',
            ltrim($api->getActionName(), '\\'),
        );
        $this->assertSame('canonical.api.account-profile', $api->getName());
        $this->assertSame(['tenant'], $api->parameterNames());
        $this->assertContains('auth', $api->gatherMiddleware());
        $this->assertContains('tenant.context', $api->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $api->methods());

        foreach ([$profile->uri(), $api->uri()] as $uri) {
            $this->assertStringNotContainsString('{user}', $uri);
            $this->assertStringNotContainsString('{membership}', $uri);
            $this->assertStringNotContainsString('{id}', $uri);
        }
    }

    public function test_profile_identity_is_server_derived_secret_minimal_repeatable_and_read_only(): void
    {
        $alpha = User::factory()->create([
            'name' => 'Authoritative Alpha',
            'email' => 'authoritative-alpha@example.test',
            'password' => bcrypt('alpha-secret-password'),
        ]);
        $alphaMembership = $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Profile');

        $beta = User::factory()->create([
            'name' => 'Foreign Beta',
            'email' => 'foreign-beta@example.test',
            'password' => bcrypt('beta-secret-password'),
        ]);
        $betaMembership = $this->membership($beta, 'beta', ['tenant.view'], 'Beta Profile');

        $before = [
            'users' => User::query()->count(),
            'memberships' => TenantMembership::query()->withoutGlobalScopes()->count(),
            'roles' => Role::query()->withoutGlobalScopes()->count(),
        ];

        $path = '/tenants/alpha/route-api/account-profile'
            .'?user_id='.$beta->id
            .'&membership_id='.$betaMembership->id
            .'&tenant=beta'
            .'&email='.$beta->email;

        $first = $this->actingAs($alpha)->getJson($path)->assertOk()->json();
        $second = $this->actingAs($alpha)->getJson($path)->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertSame($alpha->id, data_get($first, 'data.0.user_id'));
        $this->assertSame($alphaMembership->status, data_get($first, 'data.0.membership_status'));
        $this->assertSame('Authoritative Alpha', data_get($first, 'data.0.name'));
        $this->assertSame('authoritative-alpha@example.test', data_get($first, 'data.0.email'));
        $this->assertNotSame($beta->id, data_get($first, 'data.0.user_id'));
        $this->assertNotSame('Foreign Beta', data_get($first, 'data.0.name'));
        $this->assertNotSame('foreign-beta@example.test', data_get($first, 'data.0.email'));

        $profile = $first['data'][0] ?? [];
        foreach (['password', 'remember_token', 'password_hash', 'api_token', 'access_token', 'tenant_id'] as $secretKey) {
            $this->assertArrayNotHasKey($secretKey, $profile);
        }

        $after = [
            'users' => User::query()->count(),
            'memberships' => TenantMembership::query()->withoutGlobalScopes()->count(),
            'roles' => Role::query()->withoutGlobalScopes()->count(),
        ];
        $this->assertSame($before, $after);
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        try {
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

            return $membership->fresh('tenant');
        } finally {
            $context->forget();
        }
    }
}
