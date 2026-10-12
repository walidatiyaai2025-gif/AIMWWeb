<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use App\Models\BillingPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomePlanCtaVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-616576D8F7';

    public function test_canonical_ledger_row_is_the_pending_welcome_plan_cta_control(): void
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
        $this->assertSame('/welcome', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Welcome.razor', $row['current_source']);
        $this->assertStringContainsString('PlanCtaHref(plan)', $row['visible_control']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
    }

    public function test_reference_semantics_are_anonymous_register_authenticated_free_root_and_authenticated_paid_billing(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'));

        $this->assertStringContainsString('<a href="@PlanCtaHref(plan)" class="price-cta">', $source);
        $this->assertStringContainsString('if (!_isAuthenticated) return "/register";', $source);
        $this->assertStringContainsString('return IsFreeTrial(plan) ? "/" : "/account/billing";', $source);
        $this->assertStringContainsString('string.Equals(plan.Code, "free-trial", StringComparison.OrdinalIgnoreCase) || plan.Price == 0m', $source);
    }

    public function test_public_welcome_renders_only_authoritative_enabled_non_retired_plans_and_anonymous_ctas_register(): void
    {
        $this->plan('cta-free', 'CTA Free', 0, true, null, 10);
        $this->plan('cta-paid', 'CTA Paid', 1299, true, null, 20);
        $this->plan('cta-disabled', 'CTA Disabled Sentinel', 999, false, null, 30);
        $this->plan('cta-retired', 'CTA Retired Sentinel', 999, true, now(), 40);

        $response = $this->get('/welcome?tenant=foreign&plan_id=999999');

        $response->assertOk()
            ->assertSee('data-plan-code="cta-free"', false)
            ->assertSee('data-plan-code="cta-paid"', false)
            ->assertDontSee('CTA Disabled Sentinel')
            ->assertDontSee('CTA Retired Sentinel')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/register"', false)
            ->assertDontSee('href="/account/billing"', false)
            ->assertDontSee('provider_plan_id')
            ->assertDontSee('provider_product_id');
    }

    public function test_authenticated_ctas_match_source_paths_without_exposing_tenant_identity(): void
    {
        $this->plan('cta-free-auth', 'CTA Free Auth', 0, true, null, 10);
        $this->plan('cta-paid-auth', 'CTA Paid Auth', 2500, true, null, 20);

        $user = User::factory()->create([
            'name' => 'Authenticated CTA Sentinel',
            'email' => 'cta-sentinel@example.test',
        ]);
        $this->membership($user, 'private-tenant-sentinel', ['billing.view']);

        $content = $this->actingAs($user)
            ->get('/welcome?tenant=attacker-tenant')
            ->assertOk()
            ->assertSee('href="/"', false)
            ->assertSee('href="/account/billing"', false)
            ->assertDontSee('href="/register"', false)
            ->getContent();

        $this->assertStringNotContainsString('private-tenant-sentinel', $content);
        $this->assertStringNotContainsString('Authenticated CTA Sentinel', $content);
        $this->assertStringNotContainsString('cta-sentinel@example.test', $content);
        $this->assertStringNotContainsString('attacker-tenant', $content);
    }

    public function test_account_billing_bridge_is_authenticated_no_id_surface_and_redirects_one_owned_membership_to_real_guarded_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/account/billing', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class.'@billing', ltrim($route->getActionName(), '\\'));
        $this->assertSame('public.account-billing.bridge', $route->getName());
        $this->assertSame([], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->get('/account/billing')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->membership($user, 'alpha-billing-cta', ['billing.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/account/billing?tenant=foreign&tenant_id=999999')
            ->assertRedirect('/tenants/alpha-billing-cta/account/billing');

        $this->actingAs($user)
            ->get('/tenants/alpha-billing-cta/account/billing')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_account_billing_bridge_fails_closed_when_membership_context_is_missing_or_ambiguous(): void
    {
        $orphan = User::factory()->create();

        $this->actingAs($orphan)
            ->get('/account/billing')
            ->assertStatus(409);

        $multi = User::factory()->create();
        $this->membership($multi, 'multi-alpha', ['billing.view']);
        $this->membership($multi, 'multi-beta', ['billing.view']);

        $response = $this->actingAs($multi)
            ->get('/account/billing?tenant=multi-alpha');

        $response->assertStatus(409);
        $this->assertStringNotContainsString('/tenants/multi-alpha/account/billing', $response->getContent());
        $this->assertStringNotContainsString('/tenants/multi-beta/account/billing', $response->getContent());
    }

    public function test_empty_catalog_has_deterministic_non_success_state_and_no_fake_cta(): void
    {
        BillingPlan::query()->delete();

        $this->get('/welcome')
            ->assertOk()
            ->assertSee('The plan catalog is temporarily unavailable.')
            ->assertDontSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);
    }

    private function plan(
        string $code,
        string $name,
        ?int $priceMinor,
        bool $enabled,
        $retiredAt,
        int $displayOrder,
    ): BillingPlan {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => $name,
            'localized_name' => ['en' => $name, 'ar' => $name],
            'description' => $name.' description',
            'localized_description' => ['en' => $name.' description', 'ar' => $name.' description'],
            'price_minor' => $priceMinor,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => $priceMinor === 0 ? 14 : 0,
            'grace_period_days' => 7,
            'enabled' => $enabled,
            'retired_at' => $retiredAt,
            'display_order' => $displayOrder,
            'limits' => [],
            'entitlements' => [],
        ]);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => "welcome-plan-cta-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
