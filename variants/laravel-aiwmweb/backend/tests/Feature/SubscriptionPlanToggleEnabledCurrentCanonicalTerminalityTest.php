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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SubscriptionPlanToggleEnabledCurrentCanonicalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-B00411742F';

    public function test_current_canonical_identity_reuses_the_real_toggle_enabled_runtime(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));
        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));

        $this->assertSame(self::OPERATION_ID, SubscriptionPlanEnabledController::CANONICAL_OPERATION_ID);
        $this->assertStringContainsString('private async Task ToggleEnabledAsync()', $source);
        $this->assertStringContainsString('Plans.SetEnabledAsync(_editingId.Value, !_form.IsEnabled)', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('expected_enabled', $view);
    }

    public function test_toggle_requires_permission_and_active_tenant_then_persists_and_audits(): void
    {
        $plan = $this->plan('current-canonical-toggle');
        $payload = ['expected_enabled' => 1, 'enabled' => 0];

        $viewer = User::factory()->create();
        $this->membership($viewer, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($viewer)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/enabled", $payload)
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch("/tenants/foreign/admin/subscription-plans/{$plan->id}/enabled", $payload)
            ->assertNotFound();

        $admin = User::factory()->create();
        $this->membership($admin, 'admin', ['settings.manage']);

        $this->actingAs($admin)
            ->patch("/tenants/admin/admin/subscription-plans/{$plan->id}/enabled", $payload)
            ->assertRedirect('/tenants/admin/admin/subscription-plans')
            ->assertSessionHas('status', 'Plan disabled without deleting subscriber data.');

        $this->assertFalse((bool) $plan->fresh()->enabled);
        $audit = DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('SubscriptionPlan.Disable', $audit->action);
    }

    private function plan(string $code): BillingPlan
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
            'provider' => null,
            'provider_product_id' => null,
            'provider_plan_id' => null,
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
        $role = Role::query()->create(['name' => "toggle-current-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
