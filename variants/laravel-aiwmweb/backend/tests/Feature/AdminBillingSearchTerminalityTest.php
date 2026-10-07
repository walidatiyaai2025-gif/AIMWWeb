<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Http\Controllers\AdminBillingSupportController;
use App\Models\BillingAudit;
use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminBillingSearchTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-9414B3FFEF';

    public function test_exact_operation_route_is_platform_admin_tenant_scoped_and_canonical(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('SearchAsync [SearchAsync]', $operation['visible_control']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions', 'GET'));
        $this->assertSame(AdminBillingSupportController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
        $this->assertContains('platform.admin', $middleware);
    }

    public function test_search_matches_active_tenant_member_account_and_exact_paypal_reference_without_disclosure(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $member = User::factory()->create([
            'name' => 'Alpha Support User',
            'username' => 'alpha.support',
            'normalized_username' => 'ALPHA.SUPPORT',
            'email' => 'alpha.support@example.test',
        ]);
        $this->membership($member, 'alpha');

        $this->activate($tenant);
        $plan = $this->plan();

        $paypal = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-SEARCH-ALPHA'),
            'encrypted_provider_subscription_id' => 'I-SEARCH-ALPHA',
            'started_at' => now()->subMonth(),
        ]);
        TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $byUser = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?q=alpha.support')
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'data');

        $this->assertStringNotContainsString('I-SEARCH-ALPHA', $byUser->getContent());

        $byProvider = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?q=I-SEARCH-ALPHA')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $paypal->id)
            ->assertJsonPath('data.0.provider', 'paypal')
            ->assertJsonPath('data.0.masked_provider_subscription_reference', 'I-S…LPHA');

        $this->assertStringNotContainsString('I-SEARCH-ALPHA', $byProvider->getContent());

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?q=alpha')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.account_slug', 'alpha');

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?q='.$tenant->id)
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->assertDatabaseCount('billing_audits', 0);
    }

    public function test_search_is_bounded_and_foreign_tenant_guest_non_admin_and_caller_owned_scope_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($admin, 'alpha');
        $this->activate($alpha);
        $plan = $this->plan();

        for ($i = 0; $i < 55; $i++) {
            TenantSubscription::query()->create([
                'billing_plan_id' => $plan->id,
                'state' => SubscriptionState::ACTIVE,
                'started_at' => now()->subMonth(),
            ]);
        }
        app(TenantContext::class)->forget();

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('count', 50)
            ->assertJsonCount(50, 'data');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($betaAdmin, 'beta');
        $this->activate($beta);
        $betaPlan = $this->plan('beta-support-search');
        TenantSubscription::query()->create([
            'billing_plan_id' => $betaPlan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-BETA-PRIVATE'),
            'encrypted_provider_subscription_id' => 'I-BETA-PRIVATE',
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?q=I-BETA-PRIVATE')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'data');

        auth()->logout();
        $this->getJson('/api/tenants/alpha/billing/admin/subscriptions?q=foreign')
            ->assertUnauthorized();

        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'alpha');
        $this->actingAs($member)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?q=foreign')
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?tenant_id='.$beta->id.'&q=I-BETA-PRIVATE')
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?limit=500')
            ->assertUnprocessable();

        $this->assertDatabaseCount('billing_audits', 0);
    }

    private function membership(User $user, string $slug): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $this->activate($tenant);
        TenantMembership::query()->firstOrCreate([
            'user_id' => $user->id,
        ], [
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function plan(string $code = 'support-search'): BillingPlan
    {
        return BillingPlan::query()->firstOrCreate(['code' => $code], [
            'name' => 'Support Search',
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 0,
            'grace_period_days' => 7,
            'enabled' => true,
            'display_order' => 996,
            'limits' => [],
            'entitlements' => [],
        ]);
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
