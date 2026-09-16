<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionPlanSaveController;
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

final class SubscriptionPlanSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-5AF09ADABA';

    public function test_canonical_reconciliation_row_is_the_adapted_save_plan_control(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('Save plan', $row['visible_control']);
        $this->assertTrue((bool) $row['mutation']);
    }

    public function test_reference_save_contract_requires_settings_manage_and_create_or_update_then_audit_and_reload(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));

        $this->assertStringContainsString('Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)', $source);
        $this->assertStringContainsString('private async Task SaveAsync()', $source);
        $this->assertStringContainsString('Plans.CreateAsync', $source);
        $this->assertStringContainsString('Plans.UpdateAsync', $source);
        $this->assertStringContainsString('await AuditAsync(creating ? "Create" : "Update", saved);', $source);
        $this->assertStringContainsString('await ReloadAsync();', $source);
    }

    public function test_save_routes_are_web_session_authenticated_tenant_scoped_and_csrf_capable(): void
    {
        $create = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans', 'POST'));
        $update = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans/7', 'PATCH'));

        $this->assertSame(SubscriptionPlanSaveController::class.'@store', $create->getActionName());
        $this->assertSame(SubscriptionPlanSaveController::class.'@update', $update->getActionName());
        foreach ([$create, $update] as $route) {
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('tenant.context', $route->gatherMiddleware());
        }

        $view = (string) file_get_contents(resource_path('views/billing/subscription-plans-admin.blade.php'));
        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
    }

    public function test_guest_missing_permission_and_foreign_tenant_writes_fail_closed_without_side_effects(): void
    {
        $payload = $this->payload('security-denied-plan');
        $beforePlans = BillingPlan::query()->count();
        $beforeAudits = DB::table('billing_plan_audits')->count();

        $this->post('/tenants/alpha/admin/subscription-plans', $payload)->assertRedirect('/login');

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($user)->post('/tenants/alpha/admin/subscription-plans', $payload)->assertForbidden();
        $this->actingAs($user)->post('/tenants/foreign/admin/subscription-plans', $payload)->assertNotFound();

        $this->assertSame($beforePlans, BillingPlan::query()->count());
        $this->assertSame($beforeAudits, DB::table('billing_plan_audits')->count());
    }

    public function test_create_persists_source_fields_audits_server_actor_rereads_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $payload = $this->payload('security-save-plan');
        $payload['gateway_product_id'] = 'PROD-sensitive-sentinel';
        $payload['gateway_plan_id'] = 'P-sensitive-sentinel';

        $response = $this->actingAs($user)->post('/tenants/alpha/admin/subscription-plans', $payload);
        $response->assertRedirect('/tenants/alpha/admin/subscription-plans')->assertDontSee('PROD-sensitive-sentinel')->assertDontSee('P-sensitive-sentinel');

        $plan = BillingPlan::query()->where('code', 'security-save-plan')->firstOrFail();
        $this->assertSame('Security Save EN', $plan->name);
        $this->assertSame(['en' => 'Security Save EN', 'ar' => 'حفظ أمني'], $plan->localized_name);
        $this->assertSame(['en' => 'Persisted English description', 'ar' => 'وصف عربي محفوظ'], $plan->localized_description);
        $this->assertSame(1999, $plan->price_minor);
        $this->assertSame('month', $plan->billing_interval);
        $this->assertSame('PROD-sensitive-sentinel', $plan->provider_product_id);
        $this->assertSame('P-sensitive-sentinel', $plan->provider_plan_id);

        $audit = DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->sole();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('SubscriptionPlan.Create', $audit->action);
        $this->assertStringNotContainsString('PROD-sensitive-sentinel', (string) $audit->after);
        $this->assertStringNotContainsString('P-sensitive-sentinel', (string) $audit->after);

        $this->actingAs($user)->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee('Security Save EN')
            ->assertSee('Persisted English description')
            ->assertDontSee('PROD-sensitive-sentinel')
            ->assertDontSee('P-sensitive-sentinel');

        $this->actingAs($user)->post('/tenants/alpha/admin/subscription-plans', $payload)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans');
        $this->assertSame(1, BillingPlan::query()->where('code', 'security-save-plan')->count());
        $this->assertSame(1, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());

        $conflict = $payload;
        $conflict['price'] = '29.99';
        $this->actingAs($user)->post('/tenants/alpha/admin/subscription-plans', $conflict)
            ->assertSessionHasErrors('code');
        $this->assertSame(1999, $plan->fresh()->price_minor);
    }

    public function test_update_rejects_caller_identity_and_code_preserves_hidden_gateway_ids_and_noop_retry_has_no_duplicate_audit(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan('immutable-plan', 'OLD-PROD', 'OLD-PLAN');
        $payload = $this->payload(null);
        $payload['name_en'] = 'Updated EN';
        $payload['name_ar'] = 'محدث';

        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}", $payload)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans');

        $saved = $plan->fresh();
        $this->assertSame('immutable-plan', $saved->code);
        $this->assertSame('Updated EN', $saved->name);
        $this->assertSame('OLD-PROD', $saved->provider_product_id);
        $this->assertSame('OLD-PLAN', $saved->provider_plan_id);
        $this->assertSame(1, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());

        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}", $payload)
            ->assertRedirect('/tenants/alpha/admin/subscription-plans');
        $this->assertSame(1, DB::table('billing_plan_audits')->where('billing_plan_id', $plan->id)->count());

        $malicious = $payload + ['code' => 'rebound', 'tenant_id' => 999, 'actor_user_id' => 999];
        $this->actingAs($user)->patch("/tenants/alpha/admin/subscription-plans/{$plan->id}", $malicious)
            ->assertSessionHasErrors(['code', 'tenant_id', 'actor_user_id']);
        $this->assertSame('immutable-plan', $plan->fresh()->code);

        $this->actingAs($user)->patch('/tenants/alpha/admin/subscription-plans/99999999', $payload)->assertNotFound();
    }

    public function test_validation_fails_closed_and_does_not_claim_success_or_seed_fake_data(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $before = BillingPlan::query()->count();

        $invalid = $this->payload('INVALID CODE');
        $invalid['price'] = '-1';
        $invalid['currency'] = 'US';
        $this->actingAs($user)->post('/tenants/alpha/admin/subscription-plans', $invalid)
            ->assertSessionHasErrors(['code', 'price', 'currency']);

        $this->assertSame($before, BillingPlan::query()->count());
        $this->assertSame(0, DB::table('billing_plan_audits')->where('action', 'SubscriptionPlan.Create')->count());
    }

    private function payload(?string $code): array
    {
        $payload = [
            'name_en' => 'Security Save EN',
            'name_ar' => 'حفظ أمني',
            'description_en' => 'Persisted English description',
            'description_ar' => 'وصف عربي محفوظ',
            'billing_interval' => 'Monthly',
            'price' => '19.99',
            'currency' => 'USD',
            'trial_days' => 14,
            'grace_period_days' => 3,
            'is_enabled' => 1,
            'sort_order' => 321,
        ];
        if ($code !== null) {
            $payload['code'] = $code;
        }

        return $payload;
    }

    private function plan(string $code, ?string $productId = null, ?string $planId = null): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => 'Before',
            'localized_name' => ['en' => 'Before', 'ar' => 'قبل'],
            'description' => 'Before description',
            'localized_description' => ['en' => 'Before description', 'ar' => 'وصف قبل'],
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
        $role = Role::query()->create(['name' => "save-plan-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
