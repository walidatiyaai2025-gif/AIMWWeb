<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBillingSupportReadController;
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

final class SubscriptionPlansAdminBillingSupportControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-5E8896CD58';

    public function test_reference_control_is_settings_manage_navigation_to_billing_support_only(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'),
        );

        $this->assertStringContainsString(
            'Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)',
            $source,
        );
        $this->assertStringContainsString('Href="/admin/billing-support"', $source);
        $this->assertStringContainsString('Billing support', $source);
        $this->assertStringNotContainsString('OnClick="BillingSupport', $source);
    }

    public function test_source_and_destination_are_explicit_guarded_read_routes_without_resource_ids(): void
    {
        $source = Route::getRoutes()->match(
            Request::create('/tenants/alpha/admin/subscription-plans', 'GET'),
        );
        $destination = Route::getRoutes()->match(
            Request::create('/admin/billing-support', 'GET'),
        );

        $this->assertSame(SubscriptionPlansAdminReadController::class, $source->getActionName());
        $this->assertSame('tenant.admin.subscription-plans', $source->getName());
        $this->assertSame(['tenant'], $source->parameterNames());
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame(AdminBillingSupportReadController::class, ltrim($destination->getActionName(), '\\'));
        $this->assertSame('canonical.admin.billing-support', $destination->getName());
        $this->assertSame([], $destination->parameterNames());
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('platform.admin', $destination->gatherMiddleware());
        $this->assertNotContains('tenant.context', $destination->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $destination->methods());
    }

    public function test_settings_manager_sees_global_support_target_derived_from_route_not_caller_input(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $plansBefore = BillingPlan::query()->withoutGlobalScopes()->count();
        $subscriptionsBefore = TenantSubscription::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans?tenant=beta&return=%2Fadmin%2Fbilling-support%3Ftenant%3Dbeta')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Billing support')
            ->assertSee('href="'.e(route('canonical.admin.billing-support')).'"', false)
            ->assertDontSee('/admin/billing-support?tenant=beta');

        $this->assertSame($plansBefore, BillingPlan::query()->withoutGlobalScopes()->count());
        $this->assertSame($subscriptionsBefore, TenantSubscription::query()->withoutGlobalScopes()->count());
    }

    public function test_destination_independently_fails_closed_for_non_platform_admin_and_opens_for_platform_admin(): void
    {
        $manager = User::factory()->create();
        $this->membership($manager, 'alpha', ['settings.manage']);
        $manager->forceFill(['platform_admin' => false])->save();

        $this->actingAs($manager)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee(self::OPERATION_ID);

        $this->actingAs($manager)
            ->get('/admin/billing-support')
            ->assertForbidden();

        $admin = User::factory()->create();
        $this->membership($admin, 'admin-tenant', ['settings.manage']);
        $admin->forceFill(['platform_admin' => true])->save();
        $this->withoutVite();

        $this->actingAs($admin)
            ->get('/admin/billing-support')
            ->assertOk()
            ->assertSee('Billing support');
    }

    public function test_guest_missing_source_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/admin/subscription-plans')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited/admin/subscription-plans')
            ->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['settings.manage']);
        Tenant::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);

        $this->actingAs($alpha)
            ->get('/tenants/beta/admin/subscription-plans')
            ->assertNotFound();
    }

    public function test_laravel_control_is_navigation_only_and_does_not_claim_provider_success(): void
    {
        $view = (string) file_get_contents(
            resource_path('views/billing/subscription-plans-admin.blade.php'),
        );

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString("route('canonical.admin.billing-support')", $view);
        $this->assertStringNotContainsString('paypal.com', strtolower($view));
        $this->assertStringNotContainsString('payment succeeded', strtolower($view));
        $this->assertStringNotContainsString('data-provider-success', strtolower($view));
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
        $role = Role::query()->create([
            'name' => "subscription-plans-billing-support-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
