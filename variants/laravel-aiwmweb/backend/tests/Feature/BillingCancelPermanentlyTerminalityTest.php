<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Billing\Providers\BillingProvider;
use App\Models\BillingAudit;
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

/** Canonical visible-control closure: AIMW-BILL-9DD2652E1E. */
final class BillingCancelPermanentlyTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private PermanentCancellationFakePayPal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new PermanentCancellationFakePayPal;
        $this->app->instance(BillingProvider::class, $this->provider);
    }

    public function test_permanent_cancel_is_auth_tenant_rbac_validation_secret_and_retry_safe(): void
    {
        [$alpha, $owner] = $this->paidTenant('alpha');
        [$beta, $betaOwner] = $this->paidTenant('beta');
        $viewer = $this->member($alpha, ['billing.view'], 'alpha-viewer');

        $this->postJson('/api/v1/tenants/alpha/billing/cancel', ['mode' => 'permanent_provider'])
            ->assertUnauthorized();

        $this->actingAs($betaOwner->user)
            ->postJson('/api/v1/tenants/alpha/billing/cancel', ['mode' => 'permanent_provider'])
            ->assertNotFound();

        $this->actingAs($viewer->user)
            ->postJson('/api/v1/tenants/alpha/billing/cancel', ['mode' => 'permanent_provider'])
            ->assertForbidden();

        $current = $this->actingAs($owner->user)
            ->getJson('/api/v1/tenants/alpha/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.can_cancel_permanently', true);
        $this->assertStringNotContainsString('provider_subscription', $current->getContent());
        $this->assertStringNotContainsString('encrypted_', $current->getContent());

        $this->actingAs($owner->user)
            ->postJson('/api/v1/tenants/alpha/billing/cancel', [
                'mode' => 'permanent_provider',
                'subscription_id' => 999999,
                'tenant_id' => $beta->id,
            ])
            ->assertUnprocessable();
        $this->assertSame(0, $this->provider->cancelCalls);

        $accepted = $this->actingAs($owner->user)
            ->postJson('/api/v1/tenants/alpha/billing/cancel', ['mode' => 'permanent_provider'])
            ->assertAccepted()
            ->assertJsonPath('data.request_status', 'provider_accepted')
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.cancel_at_period_end', false)
            ->assertJsonPath('data.provider_state_authoritative', true);
        $this->assertSame(1, $this->provider->cancelCalls);
        $this->assertStringNotContainsString('sub-alpha', $accepted->getContent());
        $this->assertStringNotContainsString('provider_subscription', $accepted->getContent());

        $this->actingAs($owner->user)
            ->postJson('/api/v1/tenants/alpha/billing/cancel', ['mode' => 'permanent_provider'])
            ->assertAccepted()
            ->assertJsonPath('data.request_status', 'provider_accepted')
            ->assertJsonPath('data.state', 'ACTIVE');
        $this->assertSame(1, $this->provider->cancelCalls, 'Accepted retries must not resubmit the irreversible provider command.');

        $context = app(TenantContext::class);
        $context->activate($alpha, $owner);
        $subscription = TenantSubscription::query()->firstOrFail();
        $this->assertSame(SubscriptionState::ACTIVE, $subscription->state);
        $this->assertFalse($subscription->cancel_at_period_end);
        $this->assertSame(1, BillingAudit::query()->where('action', 'billing.cancellation.permanent_requested')->where('subject_id', $subscription->id)->count());
        $context->forget();
    }

    public function test_provider_snapshot_can_confirm_prior_ambiguous_cancellation_without_resubmitting(): void
    {
        [$tenant, $owner] = $this->paidTenant('alpha');
        $this->provider->reconciledStatus = 'CANCELLED';

        $this->actingAs($owner->user)
            ->postJson('/api/v1/tenants/alpha/billing/cancel', ['mode' => 'permanent_provider'])
            ->assertOk()
            ->assertJsonPath('data.request_status', 'provider_confirmed')
            ->assertJsonPath('data.state', 'CANCELLED')
            ->assertJsonPath('data.cancel_at_period_end', false);
        $this->assertSame(0, $this->provider->cancelCalls);

        $context = app(TenantContext::class);
        $context->activate($tenant, $owner);
        $subscription = TenantSubscription::query()->firstOrFail();
        $this->assertSame(SubscriptionState::CANCELLED, $subscription->state);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertSame(0, BillingAudit::query()->where('action', 'billing.cancellation.permanent_requested')->count());
        $this->assertSame(1, BillingAudit::query()->where('action', 'billing.reconciled')->count());
        $context->forget();
    }

    /** @return array{0:Tenant,1:TenantMembership} */
    private function paidTenant(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $member = $this->member($tenant, ['billing.view', 'billing.manage'], $slug.'-owner');
        $context = app(TenantContext::class);
        $context->activate($tenant, $member);
        $plan = BillingPlan::query()->where('code', 'pro')->firstOrFail();
        $plan->update(['provider' => 'paypal', 'provider_plan_id' => 'P-'.strtoupper($slug), 'price_minor' => 4900]);
        TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'sub-'.$slug),
            'encrypted_provider_subscription_id' => 'sub-'.$slug,
            'started_at' => now(),
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);
        $context->forget();

        return [$tenant, $member];
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

final class PermanentCancellationFakePayPal implements BillingProvider
{
    public int $cancelCalls = 0;

    public string $reconciledStatus = 'ACTIVE';

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
        return ['provider_subscription_id' => 'unused', 'approval_url' => 'https://example.test', 'status' => 'unused'];
    }

    public function changeSubscription(TenantSubscription $subscription, BillingPlan $plan): array
    {
        return ['requested' => true];
    }

    public function cancelSubscription(TenantSubscription $subscription): void
    {
        $this->cancelCalls++;
    }

    public function verifyAndParseWebhook(Request $request): array
    {
        return [];
    }

    public function reconcile(TenantSubscription $subscription): array
    {
        return ['status' => $this->reconciledStatus, 'provider_plan_id' => 'P-ALPHA', 'occurred_at' => now()->toAtomString()];
    }
}