<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SubscriptionPlansAdminNewPlanTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-F73C7348C3';

    public function test_reference_new_plan_semantics_match_exact_defaults(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'),
        );

        foreach ([
            'OnClick="NewPlan"',
            'private void NewPlan()',
            '_editingId = null;',
            '_form = NewForm();',
            '_entitlementValues.Clear();',
            '_error = _message = string.Empty;',
            'BillingInterval = SubscriptionPlan.MonthlyInterval',
            'Currency = "USD"',
            'IsEnabled = true',
            'GracePeriodDays = 7',
            'SortOrder = 100',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
    }

    public function test_settings_manager_sees_real_new_plan_control(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee('data-new-plan', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('data-plan-create-form', false)
            ->assertSee('js/subscription-plans-new-plan.js');
    }

    public function test_browser_contract_resets_defaults_and_never_submits(): void
    {
        $script = (string) file_get_contents(public_path('js/subscription-plans-new-plan.js'));

        foreach ([
            "setValue('code', '');",
            "setValue('name_en', '');",
            "setValue('name_ar', '');",
            "setValue('description_en', '');",
            "setValue('description_ar', '');",
            "setValue('billing_interval', 'Monthly');",
            "setValue('price', '0.00');",
            "setValue('currency', 'USD');",
            "setValue('trial_days', '0');",
            "setValue('grace_period_days', '7');",
            "setValue('sort_order', '100');",
            "setValue('gateway_product_id', '');",
            "setValue('gateway_plan_id', '');",
            'enabled.checked = true;',
            '[data-new-plan-feedback]',
            '[data-clone-current-status]',
        ] as $expected) {
            $this->assertStringContainsString($expected, $script);
        }

        foreach (['fetch(', 'XMLHttpRequest', 'requestSubmit(', '.submit('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
    }

    public function test_control_is_read_only_until_separate_save_operation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $beforePlans = DB::table('billing_plans')->count();
        $beforeAudits = DB::table('billing_plan_audits')->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee('type="button"', false)
            ->assertSee('data-new-plan', false);

        $this->assertSame($beforePlans, DB::table('billing_plans')->count());
        $this->assertSame($beforeAudits, DB::table('billing_plan_audits')->count());
    }

    public function test_authorization_and_tenant_isolation_fail_closed(): void
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
            'name' => "subscription-plans-new-plan-{$slug}-{$user->id}",
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
