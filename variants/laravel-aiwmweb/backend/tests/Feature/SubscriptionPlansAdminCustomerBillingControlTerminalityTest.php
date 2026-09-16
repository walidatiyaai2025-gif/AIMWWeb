<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\SubscriptionPlansAdminReadController;
use App\Models\BillingPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SubscriptionPlansAdminCustomerBillingControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-0CE205B851';

    public function test_canonical_reconciliation_row_is_the_adapted_customer_billing_visible_control(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/admin/subscription-plans', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor', $row['current_source']);
        $this->assertStringContainsString('/account/billing', $row['visible_control']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('medium', $row['risk']);
    }

    public function test_reference_control_is_settings_manage_navigation_only(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));

        $this->assertStringContainsString('Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)', $source);
        $this->assertStringContainsString('Href="/account/billing"', $source);
        $this->assertStringContainsString('Customer billing', $source);
    }

    public function test_source_and_destination_are_real_guarded_routes_with_no_direct_resource_id(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/account/billing', 'GET'));

        $this->assertSame(SubscriptionPlansAdminReadController::class, $source->getActionName());
        $this->assertSame('tenant.admin.subscription-plans', $source->getName());
        $this->assertSame(['tenant'], $source->parameterNames());
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($destination->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.account-billing', $destination->getName());
        $this->assertSame('billing.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['tenant'], $destination->parameterNames());
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
    }

    public function test_authorized_settings_manager_sees_tenant_derived_control_and_reaches_real_billing_workspace_without_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'billing.view']);
        $this->withoutVite();

        $plansBefore = BillingPlan::query()->withoutGlobalScopes()->count();
        $subscriptionsBefore = TenantSubscription::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans?tenant=beta&return=%2Ftenants%2Fbeta%2Faccount%2Fbilling')
            ->assertOk()
            ->assertSee(self::OPERATION_ID)
            ->assertSee('Customer billing')
            ->assertSee('href="/tenants/alpha/account/billing"', false)
            ->assertDontSee('/tenants/beta/account/billing');

        $this->actingAs($user)
            ->get('/tenants/alpha/account/billing')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($plansBefore, BillingPlan::query()->withoutGlobalScopes()->count());
        $this->assertSame($subscriptionsBefore, TenantSubscription::query()->withoutGlobalScopes()->count());
    }

    public function test_guest_missing_source_permission_foreign_tenant_and_missing_destination_permission_fail_closed(): void
    {
        $this->get('/tenants/alpha/admin/subscription-plans')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['billing.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited/admin/subscription-plans')
            ->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['settings.manage']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['settings.manage', 'billing.view']);

        $this->actingAs($alpha)
            ->get('/tenants/beta/admin/subscription-plans')
            ->assertNotFound();

        $this->actingAs($alpha)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee(self::OPERATION_ID)
            ->assertSee('Customer billing');

        $this->withoutVite();
        $this->actingAs($alpha)
            ->get('/tenants/alpha/account/billing')
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
        $role = Role::query()->create(['name' => "subscription-plans-customer-billing-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
