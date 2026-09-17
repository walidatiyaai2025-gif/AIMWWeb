<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionPlanEnabledController;
use App\Models\BillingPlan;
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

final class SubscriptionPlanEnabledTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-812D1C53B6';

    public function test_reference_contract_is_settings_manage_set_enabled_then_audit_reload_and_truthful_message(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));
        $this->assertStringContainsString('Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)', $source);
        $this->assertStringContainsString('private async Task ToggleEnabledAsync()', $source);
        $this->assertStringContainsString('Plans.SetEnabledAsync(_editingId.Value, !_form.IsEnabled)', $source);
        $this->assertStringContainsString('await AuditAsync(saved.IsEnabled ? "Enable" : "Disable", saved);', $source);
        $this->assertStringContainsString('await ReloadAsync();', $source);
    }

    public function test_route_is_session_authenticated_tenant_scoped_csrf_capable_and_visible(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans/7/enabled', 'PATCH'));
        $this->assertSame(SubscriptionPlanEnabledController::class.'@__invoke', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringContainsString('expected_enabled', $view);
    }

    public function test_guest_missing_permission_foreign_tenant_and_unknown_direct_id_fail_closed(): void
    {
        $plan = $this->plan('toggle-security');
        $payload = ['expected_enabled' => 1, 'enabled' => 0];

        $this->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", $payload)->assertRedirect('/login');

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", $payload)->assertForbidden();
        $this->actingAs($user)->patch("/tenants/foreign/admin/subscription-plans/{$plan->id}/enabled", $payload)->assertNotFound();

        $admin = User::factory()->create();
        $this->membership($admin, 'admin', ['settings.manage']);
        $this->actingAs($admin)->patch('/tenants/admin/admin/subscription-plans/99999999/enabled', $payload)->assertNotFound();

        $this->assertTrue((bool) $plan->fresh()->enabled);
        $this->assertSame(0, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());
    }

    public function test_disable_is_atomic_audited_redacted_authoritatively_reread_and_retry_safe(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan('paid-plan', 'PROD-sensitive', 'P-sensitive');
        $payload = ['expected_enabled' => 1, 'enabled' => 0];

        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", $payload)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans')
            ->assertSessionHas('status', 'Plan disabled without deleting subscriber data.');

        $saved = $plan->fresh();
        $this->assertFalse((bool) $saved->enabled);
        $audit = DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->sole();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('SubscriptionPlan.Disable', $audit->action);
        $this->assertStringNotContainsString('PROD-sensitive', (string) $audit->before);
        $this->assertStringNotContainsString('P-sensitive', (string) $audit->after);

        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", $payload)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans');
        $this->assertFalse((bool) $plan->fresh()->enabled);
        $this->assertSame(1, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());
    }

    public function test_stale_or_malicious_payload_converges_safely_without_identity_or_secret_override(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan('stale-toggle');

        $malicious = [
            'expected_enabled' => 1,
            'enabled' => 0,
            'tenant_id' => 999,
            'actor_user_id' => 999,
            'provider_plan_id' => 'P-forged',
        ];
        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", $malicious)
            ->assertSessionHasErrors(['tenant_id', 'actor_user_id', 'provider_plan_id']);
        $this->assertTrue((bool) $plan->fresh()->enabled);

        // A stale expected state is safe when the authoritative value already equals the explicit desired value.
        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", [
            'expected_enabled' => 0,
            'enabled' => 1,
        ])->assertRedirect('/tenants/alpha/admin/subscription-plans');
        $this->assertTrue((bool) $plan->fresh()->enabled);
        $this->assertSame(0, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());

        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", [
            'expected_enabled' => 1,
            'enabled' => 1,
        ])->assertSessionHasErrors('enabled');
        $this->assertSame(0, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());
    }

    private function plan(string $code, ?string $productId = null, ?string $planId = null): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => 'Toggle plan',
            'localized_name' => ['en' => 'Toggle plan', 'ar' => 'خطة'],
            'description' => 'Toggle description',
            'localized_description' => ['en' => 'Toggle description', 'ar' => 'وصف'],
            'price_minor' => 999,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 0,
            'grace_period_days' => 1,
            'enabled' => true,
            'display_order' => 77,
            'provider' => ($productId || $planId) ? 'paypal' : null,
            'provider_product_id' => $productId,
            'provider_plan_id' => $planId,
            'limits' => [],
            'entitlements' => [],
        ]);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "toggle-plan-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
