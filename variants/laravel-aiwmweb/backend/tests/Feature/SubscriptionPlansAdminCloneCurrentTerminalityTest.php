<?php

namespace Tests\Feature;

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

final class SubscriptionPlansAdminCloneCurrentTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-7BD7B04B26';

    public function test_reference_clone_current_semantics_are_bound_without_persistence(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'),
        );

        $this->assertStringContainsString('OnClick="CloneCurrent"', $source);
        $this->assertStringContainsString('private void CloneCurrent()', $source);
        $this->assertStringContainsString('_editingId = null;', $source);
        $this->assertStringContainsString('_form.Code = $"{_form.Code.Trim()}-copy";', $source);
        $this->assertStringContainsString('_form.NameEn = $"{_form.NameEn} Copy";', $source);
        $this->assertStringContainsString('_form.NameAr = $"نسخة {_form.NameAr}";', $source);
        $this->assertStringContainsString('_form.GatewayProductId = string.Empty;', $source);
        $this->assertStringContainsString('_form.GatewayPlanId = string.Empty;', $source);
        $this->assertStringContainsString('_form.IsEnabled = false;', $source);
        $this->assertStringContainsString('A disabled clone is ready. Adjust its code and details, then save it.', $source);
    }

    public function test_settings_manager_sees_clone_control_for_each_authoritative_plan(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans');

        $response
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Clone as new')
            ->assertSee(
                'href="'.e(route('tenant.admin.subscription-plans', [
                    'tenant' => 'alpha',
                    'clone' => $plan->id,
                ])).'"',
                false,
            );
    }

    public function test_clone_get_prepares_disabled_provider_free_create_form_with_source_equivalent_values(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan();

        $beforePlans = BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeAudits = DB::table('billing_plan_audits')->count();

        $response = $this->actingAs($user)
            ->get("/tenants/alpha/admin/subscription-plans?clone={$plan->id}");

        $response
            ->assertOk()
            ->assertSee('A disabled clone is ready. Adjust its code and details, then save it.')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('value="pro-monthly-copy"', false)
            ->assertSee('value="Pro Monthly Copy"', false)
            ->assertSee('value="نسخة برو شهري"', false)
            ->assertSee('Persisted English description')
            ->assertSee('وصف عربي محفوظ')
            ->assertSee('value="19.99"', false)
            ->assertSee('value="KWD"', false)
            ->assertSee('value="21"', false)
            ->assertSee('value="5"', false)
            ->assertSee('value="420"', false)
            ->assertDontSee('PROD-secret-clone')
            ->assertDontSee('P-secret-clone');

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<section data-canonical-operation="AIMW-BILL-5AF09ADABA".*?<form[^>]+>(.*?)<\/form>/s',
            $html,
        );
        preg_match(
            '/<section data-canonical-operation="AIMW-BILL-5AF09ADABA".*?<form[^>]+>(.*?)<\/form>/s',
            $html,
            $match,
        );
        $createForm = $match[1] ?? '';
        $this->assertStringContainsString('value="Yearly" selected', $createForm);
        $this->assertStringContainsString('name="is_enabled" type="checkbox" value="1"', $createForm);
        $this->assertStringNotContainsString('name="is_enabled" type="checkbox" value="1" checked', $createForm);
        $this->assertStringContainsString('name="gateway_product_id"', $createForm);
        $this->assertStringContainsString('name="gateway_plan_id"', $createForm);
        $this->assertStringNotContainsString('PROD-secret-clone', $createForm);
        $this->assertStringNotContainsString('P-secret-clone', $createForm);

        $this->assertSame($beforePlans, BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($beforeAudits, DB::table('billing_plan_audits')->count());
    }

    public function test_clone_query_cannot_override_authoritative_copy_fields_or_enable_provider_state(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan();

        $this->actingAs($user)
            ->get("/tenants/alpha/admin/subscription-plans?clone={$plan->id}&code=evil&name_en=Evil&is_enabled=1&gateway_plan_id=P-attacker")
            ->assertOk()
            ->assertSee('value="pro-monthly-copy"', false)
            ->assertSee('value="Pro Monthly Copy"', false)
            ->assertDontSee('value="evil"', false)
            ->assertDontSee('value="Evil"', false)
            ->assertDontSee('P-attacker');
    }

    public function test_invalid_clone_identifier_fails_closed_without_catalog_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $this->plan();

        $before = BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans?clone=999999')
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans?clone[]=1')
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans?clone=not-an-id')
            ->assertNotFound();

        $this->assertSame($before, BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/admin/subscription-plans?clone=1')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited/admin/subscription-plans?clone=1')
            ->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['settings.manage']);
        Tenant::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);
        $plan = $this->plan();

        $this->actingAs($alpha)
            ->get("/tenants/beta/admin/subscription-plans?clone={$plan->id}")
            ->assertNotFound();
    }

    public function test_laravel_clone_path_remains_read_only_and_does_not_claim_payment_provider_success(): void
    {
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/SubscriptionPlansAdminReadController.php'),
        );
        $view = (string) file_get_contents(
            resource_path('views/billing/subscription-plans-admin.blade.php'),
        );

        $this->assertStringContainsString('private static function cloneForm(BillingPlan $plan): array', $controller);
        $this->assertStringContainsString("'code' => trim((string) $plan->code).'-copy'", $controller);
        $this->assertStringContainsString("'is_enabled' => '0'", $controller);
        $this->assertStringNotContainsString('provider_product_id', $controller);
        $this->assertStringNotContainsString('provider_plan_id', $controller);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringNotContainsString('payment succeeded', strtolower($view));
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
            'name' => "subscription-plans-clone-current-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function plan(): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => 'pro-monthly',
            'name' => 'Pro Monthly',
            'localized_name' => [
                'en' => 'Pro Monthly',
                'ar' => 'برو شهري',
            ],
            'description' => 'Persisted English description',
            'localized_description' => [
                'en' => 'Persisted English description',
                'ar' => 'وصف عربي محفوظ',
            ],
            'price_minor' => 1999,
            'currency' => 'KWD',
            'billing_interval' => 'year',
            'trial_period_days' => 21,
            'grace_period_days' => 5,
            'enabled' => true,
            'display_order' => 420,
            'provider' => 'paypal',
            'provider_product_id' => 'PROD-secret-clone',
            'provider_plan_id' => 'P-secret-clone',
            'limits' => ['sites' => 10],
            'entitlements' => ['ai' => true],
        ]);
    }
}
