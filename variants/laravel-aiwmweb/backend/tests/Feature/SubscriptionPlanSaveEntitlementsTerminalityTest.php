<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionPlanEntitlementsController;
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

final class SubscriptionPlanSaveEntitlementsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-887504EDDD';

    public function test_reference_contract_and_rendered_control_cover_the_nine_canonical_definitions(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));
        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));

        $this->assertStringContainsString('private async Task SaveEntitlementsAsync()', $source);
        $this->assertStringContainsString('await Entitlements.SetAsync(_editingId.Value, definition.Key, value);', $source);
        $this->assertStringContainsString('await AuditAsync("UpdateEntitlements", selected);', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $view);

        foreach ([
            'sites.max',
            'email.siteRecipients.max',
            'email.schedules.max',
            'email.dashboardDigest',
            'automation.schedules.max',
            'ai.enabled',
            'ai.monthlyRequests.max',
            'backup.retentionDays',
            'premium.seo',
        ] as $key) {
            $this->assertStringContainsString($key, $view);
        }
    }

    public function test_route_is_session_authenticated_tenant_scoped_and_csrf_capable(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans/7/entitlements', 'PATCH'));

        $this->assertSame(SubscriptionPlanEntitlementsController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));
        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringContainsString("name=\"expected_state\"", $view);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed_without_mutation(): void
    {
        $plan = $this->plan('entitlement-denied');
        $payload = $this->payload($plan);
        $before = $plan->only(['limits', 'entitlements']);

        $this->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $payload)
            ->assertRedirect('/login');

        $viewer = User::factory()->create();
        $this->membership($viewer, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($viewer)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $payload)
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch("/tenants/foreign/admin/subscription-plans/{$plan->id}/entitlements", $payload)
            ->assertNotFound();

        $plan->refresh();
        $this->assertSame($before['limits'], $plan->limits);
        $this->assertSame($before['entitlements'], $plan->entitlements);
        $this->assertSame(0, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());
    }

    public function test_save_materializes_all_managed_keys_preserves_legacy_keys_and_audits_server_actor(): void
    {
        $admin = User::factory()->create();
        $this->membership($admin, 'alpha', ['settings.manage']);
        $plan = $this->plan('entitlement-save');

        $payload = $this->payload($plan);
        $payload['sites_max'] = 25;
        $payload['email_site_recipients_max'] = 40;
        $payload['email_schedules_max'] = 6;
        $payload['email_dashboard_digest'] = 1;
        $payload['automation_schedules_max'] = 12;
        $payload['ai_enabled'] = 1;
        $payload['ai_monthly_requests_max'] = 5000;
        $payload['backup_retention_days'] = 45;
        $payload['premium_seo'] = 1;

        $this->actingAs($admin)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $payload)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans')
            ->assertSessionHas('status', 'Plan limits and capabilities saved.');

        $saved = $plan->fresh();
        $this->assertSame(25, $saved->limits['sites.max']);
        $this->assertSame(40, $saved->limits['email.siteRecipients.max']);
        $this->assertSame(6, $saved->limits['email.schedules.max']);
        $this->assertSame(12, $saved->limits['automation.schedules.max']);
        $this->assertSame(5000, $saved->limits['ai.monthlyRequests.max']);
        $this->assertSame(45, $saved->limits['backup.retentionDays']);
        $this->assertTrue($saved->entitlements['email.dashboardDigest']);
        $this->assertTrue($saved->entitlements['ai.enabled']);
        $this->assertTrue($saved->entitlements['premium.seo']);

        $this->assertSame(77, $saved->limits['legacy.limit']);
        $this->assertTrue($saved->entitlements['legacy.capability']);
        $this->assertSame('keep-me', $saved->entitlements['legacy.string']);

        $audit = DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('SubscriptionPlan.UpdateEntitlements', $audit->action);
        $this->assertStringContainsString('"sites.max":25', (string) $audit->after);
        $this->assertStringNotContainsString('legacy.string', (string) $audit->after);

        $this->actingAs($admin)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee(self::OPERATION_ID)
            ->assertSee('sites.max')
            ->assertSee('25', false)
            ->assertDontSee('legacy.string');

        $retry = $this->payload($saved);
        $retry['sites_max'] = 25;
        $retry['email_site_recipients_max'] = 40;
        $retry['email_schedules_max'] = 6;
        $retry['email_dashboard_digest'] = 1;
        $retry['automation_schedules_max'] = 12;
        $retry['ai_enabled'] = 1;
        $retry['ai_monthly_requests_max'] = 5000;
        $retry['backup_retention_days'] = 45;
        $retry['premium_seo'] = 1;

        $this->actingAs($admin)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $retry)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans');

        $this->assertSame(1, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());
    }

    public function test_stale_state_validation_and_identity_injection_fail_without_partial_write(): void
    {
        $admin = User::factory()->create();
        $this->membership($admin, 'alpha', ['settings.manage']);
        $plan = $this->plan('entitlement-conflict');

        $stale = $this->payload($plan);
        $plan->limits = array_merge($plan->limits, ['sites.max' => 99]);
        $plan->save();

        $stale['sites_max'] = 2;
        $this->actingAs($admin)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $stale)
            ->assertStatus(409);

        $this->assertSame(99, $plan->fresh()->limits['sites.max']);
        $this->assertSame(0, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());

        $malicious = $this->payload($plan->fresh());
        $malicious['tenant_id'] = 999;
        $malicious['actor_user_id'] = 999;
        $malicious['provider_plan_id'] = 'P-injected';
        $malicious['sites_max'] = -1;

        $this->actingAs($admin)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $malicious)
            ->assertSessionHasErrors(['tenant_id', 'actor_user_id', 'provider_plan_id', 'sites_max']);

        $this->assertSame(99, $plan->fresh()->limits['sites.max']);
        $this->assertSame(0, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());
    }

    public function test_missing_managed_keys_are_materialized_even_when_effective_defaults_are_zero_or_false(): void
    {
        $admin = User::factory()->create();
        $this->membership($admin, 'alpha', ['settings.manage']);
        $plan = $this->plan('entitlement-defaults');
        $plan->limits = ['legacy.limit' => 77];
        $plan->entitlements = ['legacy.capability' => true];
        $plan->save();

        $this->actingAs($admin)
            ->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}/entitlements", $this->payload($plan->fresh()))
            ->assertRedirect('/tenants/alpha/admin/subscription-plans');

        $saved = $plan->fresh();
        foreach ([
            'sites.max',
            'email.siteRecipients.max',
            'email.schedules.max',
            'automation.schedules.max',
            'ai.monthlyRequests.max',
            'backup.retentionDays',
        ] as $key) {
            $this->assertArrayHasKey($key, $saved->limits);
            $this->assertSame(0, $saved->limits[$key]);
        }
        foreach (['email.dashboardDigest', 'ai.enabled', 'premium.seo'] as $key) {
            $this->assertArrayHasKey($key, $saved->entitlements);
            $this->assertFalse($saved->entitlements[$key]);
        }
        $this->assertSame(77, $saved->limits['legacy.limit']);
        $this->assertTrue($saved->entitlements['legacy.capability']);
    }

    private function payload(BillingPlan $plan): array
    {
        $form = SubscriptionPlanEntitlementsController::formState($plan);

        return [
            'expected_state' => $form['expected_state'],
            'sites_max' => $form['sites_max'],
            'email_site_recipients_max' => $form['email_site_recipients_max'],
            'email_schedules_max' => $form['email_schedules_max'],
            'email_dashboard_digest' => $form['email_dashboard_digest'] ? 1 : 0,
            'automation_schedules_max' => $form['automation_schedules_max'],
            'ai_enabled' => $form['ai_enabled'] ? 1 : 0,
            'ai_monthly_requests_max' => $form['ai_monthly_requests_max'],
            'backup_retention_days' => $form['backup_retention_days'],
            'premium_seo' => $form['premium_seo'] ? 1 : 0,
        ];
    }

    private function plan(string $code): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => 'Entitlement plan',
            'localized_name' => ['en' => 'Entitlement plan', 'ar' => 'خطة'],
            'description' => 'Entitlement description',
            'localized_description' => ['en' => 'Entitlement description', 'ar' => 'وصف'],
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
            'limits' => ['sites.max' => 3, 'legacy.limit' => 77],
            'entitlements' => ['ai.enabled' => false, 'legacy.capability' => true, 'legacy.string' => 'keep-me'],
        ]);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "save-entitlements-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
