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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminBillingSuspendTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-602CCA4A55';

    public function test_exact_operation_route_is_platform_admin_tenant_scoped_and_canonical(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('SuspendAsync [SuspendAsync]', $operation['visible_control']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions/11/suspend', 'POST'));
        $this->assertSame(AdminBillingSupportController::class.'@suspend', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
        $this->assertContains('platform.admin', $middleware);
    }

    public function test_platform_admin_suspends_access_idempotently_without_mutating_provider_binding_or_payment_state(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan();
        $providerHash = hash('sha256', 'I-SUSPEND-PRESERVED');
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => $providerHash,
            'encrypted_provider_subscription_id' => 'I-SUSPEND-PRESERVED',
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/suspend";
        $payload = ['reason' => 'Case SUP-3001 verified access suspension'];

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-suspend-1')
            ->withHeader('X-Request-Id', 'req-suspend-1')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'suspended')
            ->assertJsonPath('data.state', SubscriptionState::SUSPENDED->value)
            ->assertJsonPath('data.provider_bound', true)
            ->assertJsonPath('data.local_access_restored', false)
            ->assertJsonPath('data.payment_success_recorded', false);

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-suspend-1')
            ->withHeader('X-Request-Id', 'req-suspend-replay')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.state', SubscriptionState::SUSPENDED->value);

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.state', SubscriptionState::SUSPENDED->value)
            ->assertJsonPath('data.0.provider', 'paypal')
            ->assertJsonMissing(['encrypted_provider_subscription_id'])
            ->assertJsonMissing(['provider_subscription_id']);

        $this->activate($tenant);
        $persisted = TenantSubscription::query()->findOrFail($subscription->id);
        $this->assertSame(SubscriptionState::SUSPENDED, $persisted->state);
        $this->assertSame('paypal', $persisted->provider);
        $this->assertSame($providerHash, $persisted->provider_subscription_hash);
        $this->assertSame('I-SUSPEND-PRESERVED', $persisted->encrypted_provider_subscription_id);
        $this->assertDatabaseCount('billing_audits', 1);

        $audit = BillingAudit::query()->where('action', 'billing.support.suspended')->sole();
        $this->assertSame(self::OPERATION_ID, $audit->metadata['operation_id']);
        $this->assertSame('req-suspend-1', $audit->metadata['request_id']);
        $this->assertSame('Case SUP-3001 verified access suspension', $audit->metadata['reason']);
        $this->assertSame(SubscriptionState::ACTIVE->value, $audit->metadata['from_state']);
        $this->assertSame(SubscriptionState::SUSPENDED->value, $audit->metadata['to_state']);
        $this->assertTrue($audit->metadata['provider_bound']);
        $this->assertFalse($audit->metadata['payment_success_recorded']);
        $this->assertFalse($audit->metadata['provider_mutated']);
        $this->assertSame(0, DB::table('billing_transactions')->where('tenant_id', $tenant->id)->count());
        app(TenantContext::class)->forget();
    }

    public function test_guest_non_admin_and_foreign_tenant_subscription_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $this->membership($admin, 'alpha');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($betaAdmin, 'beta');
        $this->activate($beta);
        $plan = $this->plan('support-suspend-beta');
        $foreign = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$foreign->id}/suspend";
        $payload = ['reason' => 'Case SUP-3002 foreign target denial'];

        $this->postJson($url, $payload)->assertUnauthorized();

        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'alpha');
        $this->actingAs($member)
            ->withHeader('Idempotency-Key', 'non-admin-suspend')
            ->postJson($url, $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'foreign-suspend')
            ->postJson($url, $payload)
            ->assertNotFound();

        $this->activate($beta);
        $this->assertSame(SubscriptionState::ACTIVE, TenantSubscription::query()->findOrFail($foreign->id)->state);
        $this->assertDatabaseCount('billing_audits', 0);
        app(TenantContext::class)->forget();
    }

    public function test_invalid_transitions_validation_caller_owned_fields_and_conflicting_replay_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan();
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::TRIALING,
            'started_at' => now()->subDay(),
        ]);
        app(TenantContext::class)->forget();

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/suspend";

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'trial-suspend')
            ->postJson($url, ['reason' => 'Case SUP-3003 trial transition denied'])
            ->assertConflict();

        $this->activate($tenant);
        $subscription->forceFill(['state' => SubscriptionState::ACTIVE])->save();
        app(TenantContext::class)->forget();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'short-suspend')
            ->postJson($url, ['reason' => 'bad'])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'caller-owned-suspend')
            ->postJson($url, [
                'reason' => 'Case SUP-3004 ownership override attempt',
                'tenant_id' => 999,
                'actor_user_id' => 999,
                'provider' => 'forged',
                'provider_subscription_hash' => hash('sha256', 'forged'),
                'payment_status' => 'COMPLETED',
                'state' => SubscriptionState::ACTIVE->value,
                'grace_ends_at' => now()->addYear()->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'conflict-suspend')
            ->postJson($url, ['reason' => 'Case SUP-3005 first request'])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'conflict-suspend')
            ->postJson($url, ['reason' => 'Case SUP-3005 conflicting retry'])
            ->assertConflict();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'already-suspended')
            ->postJson($url, ['reason' => 'Case SUP-3006 already suspended'])
            ->assertConflict();

        $this->activate($tenant);
        $this->assertSame(SubscriptionState::SUSPENDED, TenantSubscription::query()->findOrFail($subscription->id)->state);
        $this->assertDatabaseCount('billing_audits', 1);
        $this->assertSame(0, DB::table('billing_transactions')->where('tenant_id', $tenant->id)->count());
        app(TenantContext::class)->forget();
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

    private function plan(string $code = 'support-suspend'): BillingPlan
    {
        return BillingPlan::query()->firstOrCreate(['code' => $code], [
            'name' => 'Support Suspend',
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 0,
            'grace_period_days' => 7,
            'enabled' => true,
            'display_order' => 995,
            'limits' => [],
            'entitlements' => [],
        ]);
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
