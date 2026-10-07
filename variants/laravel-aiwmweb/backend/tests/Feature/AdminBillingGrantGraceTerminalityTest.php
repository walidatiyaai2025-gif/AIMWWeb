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

class AdminBillingGrantGraceTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-5A0140C699';

    public function test_exact_operation_route_is_platform_admin_tenant_scoped_and_canonical(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('GrantGraceAsync [GrantGraceAsync]', $operation['visible_control']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions/11/grace', 'POST'));
        $this->assertSame(AdminBillingSupportController::class.'@grantGrace', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
        $this->assertContains('platform.admin', $middleware);
    }

    public function test_platform_admin_grants_and_extends_grace_with_idempotency_audit_and_authoritative_readback(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan();
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'paypal',
            'provider_subscription_hash' => hash('sha256', 'I-PRESERVED'),
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $now = now()->startOfSecond();
        $this->travelTo($now);
        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/grace";
        $payload = ['days' => 7, 'reason' => 'Case SUP-2001 temporary access extension'];

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-grace-1')
            ->withHeader('X-Request-Id', 'req-grace-1')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'grace_granted')
            ->assertJsonPath('data.state', SubscriptionState::GRACE->value)
            ->assertJsonPath('data.grace_ends_at', $now->copy()->addDays(7)->utc()->toIso8601String())
            ->assertJsonPath('data.payment_success_recorded', false);

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-grace-1')
            ->withHeader('X-Request-Id', 'req-grace-replay')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('mutation', 'grace_granted')
            ->assertJsonPath('data.grace_ends_at', $now->copy()->addDays(7)->utc()->toIso8601String());

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-grace-2')
            ->withHeader('X-Request-Id', 'req-grace-2')
            ->postJson($url, ['days' => 3, 'reason' => 'Case SUP-2001 extend approved support window'])
            ->assertOk()
            ->assertJsonPath('mutation', 'grace_extended')
            ->assertJsonPath('data.grace_ends_at', $now->copy()->addDays(10)->utc()->toIso8601String());

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-grace-1')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.grace_ends_at', $now->copy()->addDays(10)->utc()->toIso8601String());

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.state', SubscriptionState::GRACE->value)
            ->assertJsonPath('data.0.grace_ends_at', $now->copy()->addDays(10)->utc()->toIso8601String())
            ->assertJsonMissing(['encrypted_provider_subscription_id'])
            ->assertJsonMissing(['provider_subscription_id']);

        $this->activate($tenant);
        $persisted = TenantSubscription::query()->findOrFail($subscription->id);
        $this->assertSame(SubscriptionState::GRACE, $persisted->state);
        $this->assertSame($now->copy()->addDays(10)->timestamp, $persisted->grace_ends_at?->timestamp);
        $this->assertSame('paypal', $persisted->provider);
        $this->assertSame(hash('sha256', 'I-PRESERVED'), $persisted->provider_subscription_hash);
        $this->assertDatabaseCount('billing_audits', 2);

        $granted = BillingAudit::query()->where('action', 'billing.support.grace_granted')->sole();
        $this->assertSame(self::OPERATION_ID, $granted->metadata['operation_id']);
        $this->assertSame('req-grace-1', $granted->metadata['request_id']);
        $this->assertSame(7, $granted->metadata['additional_days']);
        $this->assertFalse($granted->metadata['payment_success_recorded']);
        $this->assertFalse($granted->metadata['provider_mutated']);

        $extended = BillingAudit::query()->where('action', 'billing.support.grace_extended')->sole();
        $this->assertSame(3, $extended->metadata['additional_days']);
        $this->assertSame($now->copy()->addDays(10)->utc()->toIso8601String(), $extended->metadata['grace_ends_at']);

        $this->assertSame(0, DB::table('billing_transactions')->where('tenant_id', $tenant->id)->count());
        app(TenantContext::class)->forget();
    }

    public function test_guest_non_admin_and_foreign_tenant_subscription_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($admin, 'alpha');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($betaAdmin, 'beta');
        $this->activate($beta);
        $plan = $this->plan();
        $foreign = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$foreign->id}/grace";
        $payload = ['days' => 7, 'reason' => 'Case SUP-2002 foreign target denial'];

        $this->postJson($url, $payload)->assertUnauthorized();

        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'alpha');
        $this->actingAs($member)
            ->withHeader('Idempotency-Key', 'non-admin-grace')
            ->postJson($url, $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'foreign-grace')
            ->postJson($url, $payload)
            ->assertNotFound();

        $this->activate($beta);
        $persisted = TenantSubscription::query()->findOrFail($foreign->id);
        $this->assertSame(SubscriptionState::ACTIVE, $persisted->state);
        $this->assertNull($persisted->grace_ends_at);
        $this->assertDatabaseCount('billing_audits', 0);
        app(TenantContext::class)->forget();
    }

    public function test_terminal_states_validation_caller_owned_fields_and_conflicting_replay_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan();

        $cancelled = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::CANCELLED,
            'started_at' => now()->subMonth(),
            'cancelled_at' => now()->subDay(),
        ]);
        $active = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $cancelledUrl = "/api/tenants/alpha/billing/admin/subscriptions/{$cancelled->id}/grace";
        $activeUrl = "/api/tenants/alpha/billing/admin/subscriptions/{$active->id}/grace";

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'cancelled-grace')
            ->postJson($cancelledUrl, ['days' => 7, 'reason' => 'Case SUP-2003 terminal state'])
            ->assertConflict();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'invalid-days')
            ->postJson($activeUrl, ['days' => 0, 'reason' => 'Case SUP-2004 invalid days'])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'short-reason')
            ->postJson($activeUrl, ['days' => 7, 'reason' => 'bad'])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'caller-owned-grace')
            ->postJson($activeUrl, [
                'days' => 7,
                'reason' => 'Case SUP-2005 ownership override attempt',
                'tenant_id' => 999,
                'actor_user_id' => 999,
                'provider_subscription_hash' => hash('sha256', 'forged'),
                'grace_ends_at' => now()->addYear()->toIso8601String(),
                'payment_status' => 'COMPLETED',
            ])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'conflict-grace')
            ->postJson($activeUrl, ['days' => 5, 'reason' => 'Case SUP-2006 first request'])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'conflict-grace')
            ->postJson($activeUrl, ['days' => 6, 'reason' => 'Case SUP-2006 conflicting retry'])
            ->assertConflict();

        $this->activate($tenant);
        $this->assertSame(SubscriptionState::CANCELLED, TenantSubscription::query()->findOrFail($cancelled->id)->state);
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

    private function plan(): BillingPlan
    {
        return BillingPlan::query()->firstOrCreate(['code' => 'support-grace'], [
            'name' => 'Support Grace',
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
