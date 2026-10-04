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

/** Canonical visible-control closure: AIMW-BILL-A8CBD94255. */
final class BillingReactivatePayPalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private ReactivationFakePayPal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ReactivationFakePayPal;
        $this->app->instance(BillingProvider::class, $this->provider);
    }

    public function test_reactivation_is_auth_tenant_rbac_idor_secret_and_retry_safe(): void
    {
        [$alpha, $owner] = $this->tenant('alpha');
        [, $betaOwner] = $this->tenant('beta');
        $viewer = $this->member($alpha, ['billing.view'], 'viewer');

        $this->postJson('/api/v1/tenants/alpha/billing/reactivate')->assertUnauthorized();
        $this->actingAs($betaOwner->user)->postJson('/api/v1/tenants/alpha/billing/reactivate')->assertNotFound();
        $this->actingAs($viewer->user)->postJson('/api/v1/tenants/alpha/billing/reactivate')->assertForbidden();

        $snapshot = $this->actingAs($owner->user)->getJson('/api/v1/tenants/alpha/billing/subscription')->assertOk()->assertJsonPath('data.can_reactivate', true);
        $this->assertStringNotContainsString('sub-alpha', $snapshot->getContent());
        $this->assertStringNotContainsString('encrypted_', $snapshot->getContent());

        $this->actingAs($owner->user)->postJson('/api/v1/tenants/alpha/billing/reactivate', ['subscription_id' => 999])->assertUnprocessable();
        $this->assertSame(0, $this->provider->reactivateCalls);

        $this->actingAs($owner->user)->postJson('/api/v1/tenants/alpha/billing/reactivate')
            ->assertAccepted()
            ->assertJsonPath('data.request_status', 'provider_accepted')
            ->assertJsonPath('data.state', 'SUSPENDED');
        $this->assertSame(1, $this->provider->reactivateCalls);

        $this->actingAs($owner->user)->postJson('/api/v1/tenants/alpha/billing/reactivate')
            ->assertAccepted()
            ->assertJsonPath('data.state', 'SUSPENDED');
        $this->assertSame(1, $this->provider->reactivateCalls);

        $context = app(TenantContext::class);
        $context->activate($alpha, $owner);
        $subscription = TenantSubscription::query()->firstOrFail();
        $this->assertSame(SubscriptionState::SUSPENDED, $subscription->state);
        $this->assertSame(1, BillingAudit::query()->where('action', 'billing.reactivation.requested')->where('subject_id', $subscription->id)->count());
        $context->forget();
    }

    public function test_provider_reconciliation_is_authoritative_and_cancelled_cannot_reactivate(): void
    {
        [, $owner] = $this->tenant('alpha');
        $this->provider->reconciledStatus = 'ACTIVE';

        $this->actingAs($owner->user)->postJson('/api/v1/tenants/alpha/billing/reactivate')
            ->assertOk()
            ->assertJsonPath('data.request_status', 'provider_confirmed')
            ->assertJsonPath('data.state', 'ACTIVE');
        $this->assertSame(0, $this->provider->reactivateCalls);
    }

    /** @return array{0:Tenant,1:TenantMembership} */
    private function tenant(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $member = $this->member($tenant, ['billing.view', 'billing.manage'], $slug.'-owner');
        $context = app(TenantContext::class);
        $context->activate($tenant, $member);
        $plan = BillingPlan::query()->where('code', 'pro')->firstOrFail();
        $plan->update(['provider' => 'paypal', 'provider_plan_id' => 'P-'.strtoupper($slug), 'price_minor' => 4900]);
        TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::SUSPENDED,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'sub-'.$slug),
            'encrypted_provider_subscription_id' => 'sub-'.$slug,
            'started_at' => now(),
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

final class ReactivationFakePayPal implements BillingProvider
{
    public int $reactivateCalls = 0;

    public string $reconciledStatus = 'SUSPENDED';

    public function name(): string { return 'paypal'; }

    public function configured(): bool { return true; }

    public function createSubscriptionIntent(TenantSubscription $subscription, BillingPlan $plan): array { return []; }

    public function changeSubscription(TenantSubscription $subscription, BillingPlan $plan): array { return ['requested' => true]; }

    public function cancelSubscription(TenantSubscription $subscription): void {}

    public function reactivateSubscription(TenantSubscription $subscription): void { $this->reactivateCalls++; }

    public function verifyAndParseWebhook(Request $request): array { return []; }

    public function reconcile(TenantSubscription $subscription): array { return ['status' => $this->reconciledStatus, 'occurred_at' => now()->toAtomString()]; }
}
