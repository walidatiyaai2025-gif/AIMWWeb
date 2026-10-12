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

    public function test_canonical_ledger_row_is_the_pending_billing_support_visible_control(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/admin/subscription-plans', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor', $row['current_source']);
        $this->assertStringContainsString('/admin/billing-support', $row['visible_control']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('medium', $row['risk']);
    }

    public function test_reference_control_is_settings_manage_navigation_only(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));

        $this->assertStringContainsString('Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)', $source);
        $this->assertStringContainsString('Href="/admin/billing-support"', $source);
        $this->assertStringContainsString('Billing support', $source);
    }

    public function test_source_and_destination_are_real_guarded_routes_without_resource_ids(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/admin/billing-support', 'GET'));

        $this->assertSame(SubscriptionPlansAdminReadController::class, ltrim($source->getActionName(), '\\'));
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

    public function test_platform_admin_settings_manager_sees_source_equivalent_control_and_reaches_real_support_surface_without_mutation(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_admin' => true])->save();
        $this->membership($user, 'alpha', ['settings.manage']);

        $plansBefore = BillingPlan::query()->withoutGlobalScopes()->count();
        $subscriptionsBefore = TenantSubscription::query()->withoutGlobalScopes()->count();
        $expectedTarget = route('canonical.admin.billing-support');

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans?tenant=beta&return=%2Fadmin%2Fbilling-support')
            ->assertOk()
            ->assertSee(self::OPERATION_ID)
            ->assertSee('Billing support')
            ->assertSee('href="'.e($expectedTarget).'"', false)
            ->assertDontSee('/tenants/beta/admin/billing-support');

        $this->actingAs($user)
            ->get('/admin/billing-support')
            ->assertOk()
            ->assertSee('Billing support');

        $this->assertSame($plansBefore, BillingPlan::query()->withoutGlobalScopes()->count());
        $this->assertSame($subscriptionsBefore, TenantSubscription::query()->withoutGlobalScopes()->count());
    }

    public function test_guest_missing_source_permission_foreign_tenant_and_non_platform_destination_fail_closed(): void
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
        $this->membership($beta, 'beta', ['settings.manage']);

        $this->actingAs($alpha)
            ->get('/tenants/beta/admin/subscription-plans')
            ->assertNotFound();

        $this->actingAs($alpha)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee(self::OPERATION_ID)
            ->assertSee('Billing support');

        $this->actingAs($alpha)
            ->get('/admin/billing-support')
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
        $role = Role::query()->create(['name' => "subscription-plans-billing-support-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
