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

class BillingProfileLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-67B6CF3962';

    public function test_exact_canonical_operation_is_the_pending_billing_profile_visible_control(): void
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
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/account/billing', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountBilling.razor', $operation['current_source']);
        $this->assertStringContainsString('/account/profile', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_destination_are_explicit_guarded_routes_not_the_spa_catch_all(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/account/billing', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/account/profile', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('billing.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('tenant.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
        $this->assertSame(['tenant'], $source->parameterNames());
        $this->assertSame(['tenant'], $destination->parameterNames());
    }

    public function test_authorized_user_reaches_real_billing_state_and_authoritative_profile_reread(): void
    {
        $user = User::factory()->create([
            'name' => 'Alpha Owner',
            'email' => 'alpha-owner@example.test',
        ]);
        $this->membership($user, 'alpha', ['tenant.view', 'billing.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/account/billing')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/account/profile')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('api.account.billing', '/tenants/alpha/route-api/billing-overview')
            ->assertJsonPath('api.account.profile', '/tenants/alpha/route-api/account-profile');

        $this->actingAs($user)
            ->getJson('/tenants/alpha/route-api/billing-overview')
            ->assertOk()
            ->assertJsonPath('data.0.section', 'subscription')
            ->assertJsonPath('data.0.state', null)
            ->assertJsonPath('data.1.section', 'entitlements')
            ->assertJsonPath('data.2.section', 'usage');

        $this->actingAs($user)
            ->getJson('/tenants/alpha/route-api/account-profile')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.name', 'Alpha Owner')
            ->assertJsonPath('data.0.email', 'alpha-owner@example.test')
            ->assertJsonPath('data.0.membership_status', 'active');
    }

    public function test_runtime_binding_uses_the_active_tenant_and_exact_canonical_operation_marker(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/billing-profile-link.tsx'));

        $this->assertStringContainsString("route.key === 'account-billing'", $app);
        $this->assertStringContainsString('<BillingProfileLink context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/account/profile')", $control);
        $this->assertStringNotContainsString('/tenants/beta/account/profile', $control);
    }

    public function test_guest_missing_permission_and_cross_tenant_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/account/billing')->assertRedirect('/login');
        $this->get('/tenants/alpha/account/profile')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/account/billing')->assertForbidden();
        $this->actingAs($limited)->getJson('/tenants/limited/route-api/billing-overview')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'billing.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'billing.view']);

        $this->actingAs($alpha)->get('/tenants/beta/account/billing')->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/beta/account/profile')->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/beta/route-api/billing-overview')->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/beta/route-api/account-profile')->assertNotFound();
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
        $role = Role::query()->create(['name' => "billing-profile-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
