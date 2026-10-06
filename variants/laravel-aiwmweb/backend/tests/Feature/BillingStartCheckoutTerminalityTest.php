<?php

namespace Tests\Feature;

use App\Billing\Providers\BillingProvider;
use App\Http\Controllers\BillingController;
use App\Models\BillingPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Canonical visible-control closure: AIMW-BILL-8DD8F167D3. */
final class BillingStartCheckoutTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutFakePayPal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new CheckoutFakePayPal;
        $this->app->instance(BillingProvider::class, $this->provider);
    }

    public function test_checkout_is_auth_tenant_rbac_identity_secret_and_idempotency_safe(): void
    {
        [$alpha, $owner] = $this->tenant('alpha', ['billing.view', 'billing.manage'], 'alpha-owner');
        [, $betaOwner] = $this->tenant('beta', ['billing.view', 'billing.manage'], 'beta-owner');
        $context = app(TenantContext::class);
        $context->activate($alpha, $owner);
        $plan = BillingPlan::query()->where('code', 'pro')->firstOrFail();
        $plan->update(['provider' => 'paypal', 'provider_plan_id' => 'P-PRO', 'price_minor' => 4900, 'enabled' => true, 'retired_at' => null]);
        $context->forget();

        $this->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro'])->assertUnauthorized();
        $this->actingAs($betaOwner->user)->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro'])->assertNotFound();

        $alphaViewer = $this->member($alpha, ['billing.view'], 'alpha-viewer');
        $this->actingAs($alphaViewer->user)
            ->withHeader('Idempotency-Key', 'billing-checkout-viewer-0001')
            ->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro'])
            ->assertForbidden();

        $this->actingAs($owner->user)
            ->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro'])
            ->assertUnprocessable();

        $this->actingAs($owner->user)
            ->withHeader('Idempotency-Key', 'billing-checkout-extra-0001')
            ->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro', 'tenant_id' => 999, 'user_id' => 999])
            ->assertUnprocessable();
        $this->assertSame(0, $this->provider->checkoutCalls);

        $first = $this->actingAs($owner->user)
            ->withHeader('Idempotency-Key', 'billing-checkout-alpha-0001')
            ->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_PROVIDER_CONFIRMATION')
            ->assertJsonPath('data.approval_url', 'https://www.paypal.com/checkoutnow?token=alpha');
        $this->assertSame(1, $this->provider->checkoutCalls);
        $this->assertStringNotContainsString('sub-alpha-secret', $first->getContent());
        $this->assertStringNotContainsString('provider_subscription_id', $first->getContent());

        $this->actingAs($owner->user)
            ->withHeader('Idempotency-Key', 'billing-checkout-alpha-0001')
            ->postJson('/api/v1/tenants/alpha/billing/checkout', ['plan_code' => 'pro'])
            ->assertCreated()
            ->assertJsonPath('data.approval_url', 'https://www.paypal.com/checkoutnow?token=alpha');
        $this->assertSame(1, $this->provider->checkoutCalls, 'The same checkout idempotency key must not create a second provider intent.');

        $context->activate($alpha, $owner);
        $subscription = TenantSubscription::query()->firstOrFail();
        $this->assertSame('paypal', $subscription->provider);
        $this->assertNotNull($subscription->provider_subscription_hash);
        $context->forget();

        $route = app('router')->getRoutes()->getByName('api.v1.billing.checkout');
        $this->assertNotNull($route);
        $this->assertSame(BillingController::CHECKOUT_OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
    }

    /** @return array{0:Tenant,1:TenantMembership} */
    private function tenant(string $slug, array $permissions, string $roleName): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        return [$tenant, $this->member($tenant, $permissions, $roleName)];
    }

    private function member(Tenant $tenant, array $permissions, string $roleName): TenantMembership
    {
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return $membership;
    }
}

final class CheckoutFakePayPal implements BillingProvider
{
    public int $checkoutCalls = 0;

    public function name(): string
    {
        return 'paypal';
    }

    public function configured(): bool
    {
        return true;
    }

    public function createSubscriptionIntent(TenantSubscription $subscription, BillingPlan $plan): array
    {
        $this->checkoutCalls++;

        return [
            'provider_subscription_id' => 'sub-alpha-secret',
            'approval_url' => 'https://www.paypal.com/checkoutnow?token=alpha',
            'status' => 'APPROVAL_PENDING',
        ];
    }

    public function changeSubscription(TenantSubscription $subscription, BillingPlan $plan): array
    {
        return ['requested' => true];
    }

    public function cancelSubscription(TenantSubscription $subscription): void
    {
        // Checkout proof does not exercise provider cancellation.
    }

    public function reactivateSubscription(TenantSubscription $subscription): void
    {
        // Checkout proof does not exercise provider reactivation.
    }

    public function verifyAndParseWebhook(Request $request): array
    {
        return [];
    }

    public function reconcile(TenantSubscription $subscription): array
    {
        return ['status' => 'SUSPENDED', 'occurred_at' => now()->toAtomString()];
    }
}
