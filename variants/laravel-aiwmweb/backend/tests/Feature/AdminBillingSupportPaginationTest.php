<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminBillingSupportPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paged_search_uses_real_totals_stable_order_overflow_clamping_and_legacy_shape(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($admin, 'alpha');
        $this->activate($alpha);
        $plan = $this->plan('pagination-alpha');
        $ids = [];
        for ($i = 0; $i < 63; $i++) {
            $ids[] = TenantSubscription::query()->create([
                'billing_plan_id' => $plan->id,
                'state' => SubscriptionState::ACTIVE,
                'started_at' => now()->subDays(2),
            ])->id;
        }
        app(TenantContext::class)->forget();

        $first = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('count', 25)
            ->assertJsonPath('total', 63)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 25)
            ->assertJsonPath('pagination_authoritative', true)
            ->assertJsonCount(25, 'data');
        $this->assertSame($ids[62], $first->json('data.0.id'));

        $second = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=2&per_page=25')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('total', 63)
            ->assertJsonCount(25, 'data');
        $this->assertSame($ids[37], $second->json('data.0.id'));

        $overflow = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1000000&per_page=25')
            ->assertOk()
            ->assertJsonPath('current_page', 3)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('total', 63)
            ->assertJsonCount(13, 'data');
        $this->assertSame($ids[12], $overflow->json('data.0.id'));

        // No pagination parameters: historical SearchAsync response is unchanged.
        $legacy = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('count', 50)
            ->assertJsonCount(50, 'data');
        foreach (['total', 'current_page', 'last_page', 'per_page', 'pagination_authoritative'] as $key) {
            $this->assertArrayNotHasKey($key, $legacy->json());
        }
        $this->assertDatabaseCount('billing_audits', 0);
    }

    public function test_paged_search_is_tenant_safe_authorized_validated_and_redacted(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($admin, 'alpha');
        $this->activate($alpha);
        $plan = $this->plan('pagination-alpha');
        TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-ALPHA-SECRET'),
            'encrypted_provider_subscription_id' => 'I-ALPHA-SECRET',
            'started_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($betaAdmin, 'beta');
        $this->activate($beta);
        $betaPlan = $this->plan('pagination-beta');
        TenantSubscription::query()->create([
            'billing_plan_id' => $betaPlan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-BETA-PRIVATE'),
            'encrypted_provider_subscription_id' => 'I-BETA-PRIVATE',
            'started_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $response = $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1&per_page=10&search=alpha')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('I-ALPHA-SECRET', $response->getContent());
        $this->assertStringNotContainsString('I-BETA-PRIVATE', $response->getContent());

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1&per_page=10&search=I-BETA-PRIVATE')
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');

        $this->actingAs($admin)
            ->getJson('/api/tenants/beta/billing/admin/subscriptions?page=1&per_page=25')
            ->assertNotFound();
        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?per_page=999')
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=-1')
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1000001')
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?tenant_id='.$beta->id.'&page=1')
            ->assertUnprocessable();

        auth()->logout();
        $this->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1')->assertUnauthorized();
        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'alpha');
        $this->actingAs($member)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions?page=1')->assertForbidden();

        $this->assertDatabaseCount('billing_audits', 0);
    }

    private function membership(User $user, string $slug): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $this->activate($tenant);
        TenantMembership::query()->firstOrCreate(['user_id' => $user->id], ['status' => 'active']);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function plan(string $code): BillingPlan
    {
        return BillingPlan::query()->firstOrCreate(['code' => $code], [
            'name' => 'Pagination Plan',
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 0,
            'grace_period_days' => 7,
            'enabled' => true,
            'display_order' => 998,
            'limits' => [],
            'entitlements' => [],
        ]);
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
