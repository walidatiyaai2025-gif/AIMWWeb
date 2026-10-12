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

    public function test_reference_clone_current_semantics_are_exactly_local_editor_state_only(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'),
        );

        $this->assertStringContainsString('OnClick="CloneCurrent"', $source);
        $this->assertStringContainsString('private void CloneCurrent()', $source);
        $this->assertStringContainsString('if (!_editingId.HasValue) return;', $source);
        $this->assertStringContainsString('_editingId = null;', $source);
        $this->assertStringContainsString('_form.Code = $"{_form.Code.Trim()}-copy";', $source);
        $this->assertStringContainsString('_form.NameEn = $"{_form.NameEn} Copy";', $source);
        $this->assertStringContainsString('_form.NameAr = $"نسخة {_form.NameAr}";', $source);
        $this->assertStringContainsString('_form.GatewayProductId = string.Empty;', $source);
        $this->assertStringContainsString('_form.GatewayPlanId = string.Empty;', $source);
        $this->assertStringContainsString('_form.IsEnabled = false;', $source);
        $this->assertStringContainsString(
            'A disabled clone is ready. Adjust its code and details, then save it.',
            $source,
        );
    }

    public function test_settings_manager_sees_clone_control_bound_to_each_real_editor(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $plan = $this->plan();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans');

        $response
            ->assertOk()
            ->assertSee('data-plan-create-form', false)
            ->assertSee('data-plan-editor', false)
            ->assertSee('data-clone-current', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Clone as new')
            ->assertSee('data-plan-code="'.e($plan->code).'"', false)
            ->assertSee('js/subscription-plans-clone-current.js')
            ->assertDontSee('PROD-secret-clone')
            ->assertDontSee('P-secret-clone');
    }

    public function test_clone_browser_contract_copies_live_unsaved_editor_values_and_never_submits(): void
    {
        $script = (string) file_get_contents(
            public_path('js/subscription-plans-clone-current.js'),
        );

        $this->assertStringContainsString("button.addEventListener('click'", $script);
        $this->assertStringContainsString("article?.querySelector('[data-plan-editor]')", $script);
        $this->assertStringContainsString("const planCode = String(article.dataset.planCode ?? '').trim();", $script);
        $this->assertStringContainsString("setValue(createForm, 'code', `${planCode}-copy`);", $script);
        $this->assertStringContainsString("setValue(createForm, 'name_en', `${value(editor, 'name_en')} Copy`);", $script);
        $this->assertStringContainsString("setValue(createForm, 'name_ar', `نسخة ${value(editor, 'name_ar')}`);", $script);

        foreach ([
            'description_en',
            'description_ar',
            'billing_interval',
            'price',
            'currency',
            'trial_days',
            'grace_period_days',
            'sort_order',
        ] as $field) {
            $this->assertStringContainsString(
                "setValue(createForm, '{$field}', value(editor, '{$field}'));",
                $script,
            );
        }

        $this->assertStringContainsString('createEnabled.checked = false;', $script);
        $this->assertStringContainsString("setValue(createForm, 'gateway_product_id', '');", $script);
        $this->assertStringContainsString("setValue(createForm, 'gateway_plan_id', '');", $script);
        $this->assertStringContainsString('status.hidden = false;', $script);

        $this->assertStringNotContainsString('fetch(', $script);
        $this->assertStringNotContainsString('XMLHttpRequest', $script);
        $this->assertStringNotContainsString('requestSubmit(', $script);
        $this->assertStringNotContainsString('.submit(', $script);
    }

    public function test_opening_page_and_clone_control_are_read_only_until_user_explicitly_saves(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $this->plan();

        $beforePlans = BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeAudits = DB::table('billing_plan_audits')->count();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans');

        $response
            ->assertOk()
            ->assertSee('type="button"', false)
            ->assertSee('data-clone-current', false)
            ->assertSee('A disabled clone is ready. Adjust its code and details, then save it.', false)
            ->assertSee('hidden', false);

        $this->assertSame(
            $beforePlans,
            BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all(),
        );
        $this->assertSame($beforeAudits, DB::table('billing_plan_audits')->count());
    }

    public function test_clone_control_never_exposes_or_copies_provider_identifiers(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $this->plan();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans');

        $response
            ->assertOk()
            ->assertDontSee('PROD-secret-clone')
            ->assertDontSee('P-secret-clone');

        $script = (string) file_get_contents(public_path('js/subscription-plans-clone-current.js'));
        $this->assertStringContainsString("setValue(createForm, 'gateway_product_id', '');", $script);
        $this->assertStringContainsString("setValue(createForm, 'gateway_plan_id', '');", $script);
        $this->assertStringNotContainsString('provider_product_id', $script);
        $this->assertStringNotContainsString('provider_plan_id', $script);
        $this->assertStringNotContainsString('paypal.com', strtolower($script));
        $this->assertStringNotContainsString('payment succeeded', strtolower($script));
    }

    public function test_no_catalog_has_no_clone_button_and_does_not_seed_sample_data(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        BillingPlan::query()->delete();

        $this->assertDatabaseCount('billing_plans', 0);

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertDontSee('data-clone-current ', false)
            ->assertDontSee('Clone as new');

        $this->assertDatabaseCount('billing_plans', 0);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
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
        $this->plan();

        $this->actingAs($alpha)
            ->get('/tenants/beta/admin/subscription-plans')
            ->assertNotFound();
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
