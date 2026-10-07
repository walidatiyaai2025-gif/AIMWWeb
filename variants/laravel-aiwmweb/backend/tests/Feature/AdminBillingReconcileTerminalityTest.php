<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Billing\Providers\BillingProvider;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

// Exact-head CI retrigger after parity materialization; canonical scope remains AIMW-BILL-7ECC5F8CBA.
final class AdminBillingReconcileTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-7ECC5F8CBA';

    private ReconcileFakePayPal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ReconcileFakePayPal;
        $this->app->instance(BillingProvider::class, $this->provider);
    }

    public function test_exact_operation_route_is_platform_admin_tenant_scoped_and_canonical(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('ReconcileAsync [ReconcileAsync]', $operation['visible_control']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions/11/reconcile', 'POST'));
        $this->assertSame(AdminBillingSupportController::class.'@reconcilePayPal', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
        $this->assertContains('platform.admin', $middleware);
    }

    public function test_platform_admin_applies_authoritative_paypal_snapshot_with_idempotency_audit_and_readback(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);

        $basic = $this->plan('reconcile-basic', 'Basic', null);
        $pro = $this->plan('reconcile-pro', 'Pro', 'P-PRO');

        $providerReference = 'I-ALPHA-RECONCILE';
        $observedAt = now()->startOfSecond();
        $previousEvidence = $observedAt->copy()->subDay();
        $periodStart = $observedAt->copy()->subHour();
        $periodEnd = $observedAt->copy()->addMonth();

        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $basic->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', $providerReference),
            'encrypted_provider_subscription_id' => $providerReference,
            'started_at' => now()->subMonth(),
            'last_provider_event_at' => $previousEvidence,
        ]);
        app(TenantContext::class)->forget();

        $this->provider->snapshot = [
            'provider_subscription_id' => $providerReference,
            'status' => 'ACTIVE',
            'provider_plan_id' => 'P-PRO',
            'occurred_at' => $observedAt->toIso8601String(),
            'current_period_start' => $periodStart->toIso8601String(),
            'current_period_end' => $periodEnd->toIso8601String(),
            'cancel_at_period_end' => false,
        ];

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/reconcile";
        $payload = ['reason' => 'Case SUP-3001 verify authoritative PayPal state'];

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reconcile-1')
            ->withHeader('X-Request-Id', 'req-reconcile-1')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'reconciled_changed')
            ->assertJsonPath('changed', true)
            ->assertJsonPath('data.state', SubscriptionState::ACTIVE->value)
            ->assertJsonPath('data.billing_plan_id', $pro->id)
            ->assertJsonPath('data.payment_success_recorded', false)
            ->assertJsonMissing(['encrypted_provider_subscription_id'])
            ->assertJsonMissing(['provider_subscription_id']);

        $this->assertSame(1, $this->provider->reconcileCalls);

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reconcile-1')
            ->withHeader('X-Request-Id', 'req-reconcile-replay')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.billing_plan_id', $pro->id);

        $this->assertSame(1, $this->provider->reconcileCalls);

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reconcile-1')
            ->postJson($url, ['reason' => 'Case SUP-3001 conflicting replay reason'])
            ->assertConflict();

        $this->activate($tenant);
        $persisted = TenantSubscription::query()->findOrFail($subscription->id);
        $this->assertSame($pro->id, $persisted->billing_plan_id);
        $this->assertSame($observedAt->timestamp, $persisted->last_provider_event_at?->timestamp);
        $this->assertSame($periodStart->timestamp, $persisted->current_period_start?->timestamp);
        $this->assertSame($periodEnd->timestamp, $persisted->current_period_end?->timestamp);

        $audit = BillingAudit::query()->where('action', 'billing.support.reconciled')->sole();
        $this->assertSame(self::OPERATION_ID, $audit->metadata['operation_id']);
        $this->assertSame('req-reconcile-1', $audit->metadata['request_id']);
        $this->assertTrue($audit->metadata['changed']);
        $this->assertTrue($audit->metadata['plan_changed']);
        $this->assertFalse($audit->metadata['payment_success_recorded']);
        $this->assertFalse($audit->metadata['provider_mutated']);
        $this->assertNotSame($providerReference, $audit->metadata['provider_reference']);
        $this->assertStringNotContainsString($providerReference, json_encode($audit->metadata, JSON_THROW_ON_ERROR));

        $this->assertSame(0, DB::table('billing_transactions')->where('tenant_id', $tenant->id)->count());
        app(TenantContext::class)->forget();
    }

    public function test_guest_non_admin_foreign_tenant_and_caller_owned_provider_fields_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($admin, 'alpha');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($betaAdmin, 'beta');
        $this->activate($beta);
        $plan = $this->plan('reconcile-beta', 'Beta', 'P-BETA');
        $foreign = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-BETA'),
            'encrypted_provider_subscription_id' => 'I-BETA',
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$foreign->id}/reconcile";
        $payload = ['reason' => 'Case SUP-3002 foreign target denial'];

        $this->postJson($url, $payload)->assertUnauthorized();

        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'alpha');
        $this->actingAs($member)
            ->withHeader('Idempotency-Key', 'non-admin-reconcile')
            ->postJson($url, $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'foreign-reconcile')
            ->postJson($url, $payload)
            ->assertNotFound();

        $this->activate($alpha);
        $localPlan = $this->plan('reconcile-alpha', 'Alpha', 'P-ALPHA');
        $local = TenantSubscription::query()->create([
            'billing_plan_id' => $localPlan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-ALPHA'),
            'encrypted_provider_subscription_id' => 'I-ALPHA',
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'caller-owned-reconcile')
            ->postJson("/api/tenants/alpha/billing/admin/subscriptions/{$local->id}/reconcile", [
                'reason' => 'Case SUP-3003 ownership override attempt',
                'tenant_id' => 999,
                'actor_user_id' => 999,
                'provider_subscription_id' => 'I-FORGED',
                'provider_plan_id' => 'P-FORGED',
                'provider_status' => 'ACTIVE',
                'payment_status' => 'COMPLETED',
                'billing_plan_id' => 999,
                'last_provider_event_at' => now()->addYear()->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->assertSame(0, $this->provider->reconcileCalls);
        $this->assertDatabaseCount('billing_audits', 0);
    }

    public function test_failed_provider_snapshot_does_not_stick_idempotency_and_same_key_can_retry(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan('reconcile-retry', 'Retry', 'P-RETRY');
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-RETRY'),
            'encrypted_provider_subscription_id' => 'I-RETRY',
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $this->provider->throwOnReconcile = true;
        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/reconcile";
        $payload = ['reason' => 'Case SUP-3004 provider retry'];

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reconcile-retry')
            ->postJson($url, $payload)
            ->assertStatus(500);

        $this->assertSame(1, $this->provider->reconcileCalls);

        $this->provider->throwOnReconcile = false;
        $this->provider->snapshot = [
            'provider_subscription_id' => 'I-RETRY',
            'status' => 'ACTIVE',
            'provider_plan_id' => 'P-RETRY',
            'occurred_at' => now()->startOfSecond()->toIso8601String(),
            'current_period_start' => null,
            'current_period_end' => null,
            'cancel_at_period_end' => false,
        ];

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reconcile-retry')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.payment_success_recorded', false);

        $this->assertSame(2, $this->provider->reconcileCalls);
    }

    private function membership(User $user, string $slug): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $this->activate($tenant);
        TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function plan(string $code, string $name, ?string $providerPlanId): BillingPlan
    {
        return BillingPlan::query()->firstOrCreate(['code' => $code], [
            'name' => $name,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 0,
            'grace_period_days' => 7,
            'enabled' => true,
            'display_order' => 997,
            'limits' => [],
            'entitlements' => [],
            'provider' => $providerPlanId ? 'paypal' : null,
            'provider_plan_id' => $providerPlanId,
        ]);
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}

final class ReconcileFakePayPal implements BillingProvider
{
    public int $reconcileCalls = 0;

    public bool $throwOnReconcile = false;

    public array $snapshot = [];

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
        return [];
    }

    public function changeSubscription(TenantSubscription $subscription, BillingPlan $plan): array
    {
        return ['requested' => true];
    }

    public function cancelSubscription(TenantSubscription $subscription): void {}

    public function verifyAndParseWebhook(Request $request): array
    {
        return [];
    }

    public function reconcile(TenantSubscription $subscription): array
    {
        $this->reconcileCalls++;
        if ($this->throwOnReconcile) {
            throw new RuntimeException('Synthetic PayPal reconciliation outage.');
        }

        return $this->snapshot;
    }
}
