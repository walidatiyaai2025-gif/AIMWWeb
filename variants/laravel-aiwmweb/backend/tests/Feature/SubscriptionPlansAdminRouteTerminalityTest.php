<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionPlansAdminReadController;
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

final class SubscriptionPlansAdminRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-B86D339C12';

    public function test_canonical_reconciliation_row_is_the_adapted_subscription_plans_admin_route(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('/admin/subscription-plans', $row['route_screen']);
        $this->assertSame('Open/render route', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
    }

    public function test_reference_requires_settings_manage_and_loads_the_real_catalog(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SubscriptionPlansAdmin.razor'));

        $this->assertStringContainsString('@page "/admin/subscription-plans"', $source);
        $this->assertStringContainsString('Authorize(Policy = ApplicationPermissionCatalog.SettingsManage)', $source);
        $this->assertStringContainsString('Plans.ListAsync(includeDisabled: true)', $source);
    }

    public function test_route_is_explicit_authenticated_tenant_scoped_and_has_no_direct_resource_id(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/subscription-plans', 'GET'));

        $this->assertSame(SubscriptionPlansAdminReadController::class, $route->getActionName());
        $this->assertSame('tenant.admin.subscription-plans', $route->getName());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/admin/subscription-plans')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($user)->get('/tenants/alpha/admin/subscription-plans')->assertForbidden();
        $this->actingAs($user)->get('/tenants/foreign/admin/subscription-plans')->assertNotFound();
    }

    public function test_settings_manager_reads_persisted_catalog_without_get_side_effect_or_provider_identifier_exposure(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        BillingPlan::query()->create([
            'code' => 'starter-monthly',
            'name' => 'Starter Monthly',
            'localized_name' => ['ar' => 'المبتدئة الشهرية'],
            'description' => 'Persisted starter plan',
            'price_minor' => 1299,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 14,
            'grace_period_days' => 3,
            'enabled' => true,
            'display_order' => 10,
            'provider' => 'paypal',
            'provider_product_id' => 'PROD-secret-sentinel',
            'provider_plan_id' => 'P-secret-sentinel',
            'limits' => ['sites' => 3],
            'entitlements' => ['ai' => true],
        ]);
        BillingPlan::query()->create([
            'code' => 'free-trial',
            'name' => 'Free Trial',
            'description' => null,
            'price_minor' => 0,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 30,
            'grace_period_days' => 0,
            'enabled' => false,
            'display_order' => 20,
            'limits' => [],
            'entitlements' => [],
        ]);

        $before = BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all();
        $auditCount = \DB::table('billing_plan_audits')->count();

        $response = $this->actingAs($user)->get('/tenants/alpha/admin/subscription-plans');

        $response->assertOk()
            ->assertSee('Subscription Plans')
            ->assertSee('starter-monthly')
            ->assertSee('Starter Monthly')
            ->assertSee('Persisted starter plan')
            ->assertSee('12.99 USD')
            ->assertSee('free-trial')
            ->assertDontSee('PROD-secret-sentinel')
            ->assertDontSee('P-secret-sentinel')
            ->assertDontSee('AIMW-BILL-0CE205B851')
            ->assertDontSee('AIMW-BILL-5E8896CD58')
            ->assertDontSee('AIMW-BILL-F73C7348C3');

        $this->assertSame($before, BillingPlan::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($auditCount, \DB::table('billing_plan_audits')->count());
    }

    public function test_empty_catalog_is_truthful_and_does_not_seed_sample_data(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $this->assertDatabaseCount('billing_plans', 0);
        $this->actingAs($user)->get('/tenants/alpha/admin/subscription-plans')
            ->assertOk()
            ->assertSee('No persisted subscription plans.');
        $this->assertDatabaseCount('billing_plans', 0);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "subscription-plans-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
