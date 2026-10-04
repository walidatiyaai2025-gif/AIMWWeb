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
use LogicException;
use Tests\TestCase;

class AdminBillingReactivateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-29A4267B87';

    public function test_exact_operation_route_is_platform_admin_tenant_scoped_and_canonical(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('ReactivateAsync [ReactivateAsync]', $operation['visible_control']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions/11/reactivate', 'POST'));
        $this->assertSame(AdminBillingSupportController::class.'@reactivate', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
        $this->assertContains('platform.admin', $middleware);

        $readRoute = Route::getRoutes()->match(Request::create('/api/tenants/alpha/billing/admin/subscriptions', 'GET'));
        $this->assertSame(AdminBillingSupportController::class.'@index', ltrim($readRoute->getActionName(), '\\'));
        $this->assertSame('canonical.api.billing-support.index', $readRoute->getName());
        $this->assertContains('platform.admin', $readRoute->gatherMiddleware());
    }

    public function test_platform_admin_reactivates_local_suspension_with_idempotent_authoritative_reread_and_audit(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan();
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::SUSPENDED,
            'provider' => null,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $payload = ['reason' => 'Case SUP-1001 verified local lockout'];

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reactivate-1')
            ->withHeader('X-Request-Id', 'req-support-1')
            ->postJson("/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/reactivate", $payload)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'reactivated')
            ->assertJsonPath('data.state', SubscriptionState::ACTIVE->value)
            ->assertJsonPath('data.provider_bound', false)
            ->assertJsonPath('data.local_access_restored', true)
            ->assertJsonPath('data.payment_success_recorded', false);

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'support-reactivate-1')
            ->withHeader('X-Request-Id', 'req-support-replay')
            ->postJson("/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/reactivate", $payload)
            ->assertOk()
            ->assertJsonPath('data.state', SubscriptionState::ACTIVE->value);

        $this->actingAs($admin)
            ->getJson('/api/tenants/alpha/billing/admin/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.state', SubscriptionState::ACTIVE->value)
            ->assertJsonMissing(['encrypted_provider_subscription_id'])
            ->assertJsonMissing(['provider_subscription_id']);

        $this->activate($tenant);
        $persisted = TenantSubscription::query()->findOrFail($subscription->id);
        $this->assertSame(SubscriptionState::ACTIVE, $persisted->state);
        $this->assertNull($persisted->provider);
        $this->assertNull($persisted->provider_subscription_hash);
        $this->assertNull($persisted->encrypted_provider_subscription_id);
        $this->assertDatabaseCount('billing_audits', 1);
        $this->assertDatabaseHas('billing_audits', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $admin->id,
            'action' => 'billing.support.reactivated',
            'subject_type' => 'subscription',
            'subject_id' => (string) $subscription->id,
        ]);
        $audit = BillingAudit::query()->where('action', 'billing.support.reactivated')->sole();
        $this->assertSame('req-support-1', $audit->metadata['request_id']);
        $this->assertSame('Case SUP-1001 verified local lockout', $audit->metadata['reason']);
        $this->assertFalse($audit->metadata['payment_success_recorded']);
        $this->assertFalse($audit->metadata['provider_mutated']);
        $this->assertSame(0, DB::table('billing_transactions')->where('tenant_id', $tenant->id)->count());
        try {
            $audit->update(['action' => 'tampered']);
            $this->fail('Billing audit must be immutable.');
        } catch (LogicException) {
            $this->assertSame('billing.support.reactivated', $audit->fresh()->action);
        }
        app(TenantContext::class)->forget();
    }

    public function test_guest_non_admin_and_foreign_tenant_ids_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($admin, 'alpha');

        $betaAdmin = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($betaAdmin, 'beta');
        $this->activate($beta);
        $plan = $this->plan();
        $foreign = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::SUSPENDED,
            'provider' => null,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $this->postJson("/api/tenants/alpha/billing/admin/subscriptions/{$foreign->id}/reactivate", ['reason' => 'Guest denied'])
            ->assertUnauthorized();

        $member = User::factory()->create(['platform_admin' => false]);
        $this->membership($member, 'alpha');
        $this->actingAs($member)
            ->withHeader('Idempotency-Key', 'non-admin')
            ->postJson("/api/tenants/alpha/billing/admin/subscriptions/{$foreign->id}/reactivate", ['reason' => 'Non admin denied'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'foreign-target')
            ->postJson("/api/tenants/alpha/billing/admin/subscriptions/{$foreign->id}/reactivate", ['reason' => 'Foreign target denied'])
            ->assertNotFound();

        $this->activate($beta);
        $this->assertSame(SubscriptionState::SUSPENDED, TenantSubscription::query()->findOrFail($foreign->id)->state);
        app(TenantContext::class)->forget();
    }

    public function test_provider_backed_subscription_reason_and_caller_owned_fields_are_rejected_without_fake_success(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = $this->membership($admin, 'alpha');
        $this->activate($tenant);
        $plan = $this->plan();
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::SUSPENDED,
            'provider' => null,
            'provider_subscription_hash' => hash('sha256', 'I-PROVIDER-REFERENCE'),
            'encrypted_provider_subscription_id' => null,
            'started_at' => now()->subMonth(),
        ]);
        app(TenantContext::class)->forget();

        $url = "/api/tenants/alpha/billing/admin/subscriptions/{$subscription->id}/reactivate";

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'provider-backed')
            ->postJson($url, ['reason' => 'Provider case must reconcile'])
            ->assertConflict();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'too-short')
            ->postJson($url, ['reason' => 'bad'])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->withHeader('Idempotency-Key', 'caller-owned')
            ->postJson($url, [
                'reason' => 'Attempt ownership override',
                'tenant_id' => 999,
                'actor_user_id' => 999,
                'provider_subscription_hash' => hash('sha256', 'forged'),
                'payment_status' => 'COMPLETED',
            ])
            ->assertUnprocessable();

        $this->activate($tenant);
        $persisted = TenantSubscription::query()->findOrFail($subscription->id);
        $this->assertSame(SubscriptionState::SUSPENDED, $persisted->state);
        $this->assertNull($persisted->provider);
        $this->assertNotNull($persisted->provider_subscription_hash);
        $this->assertDatabaseCount('billing_audits', 0);
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
        return BillingPlan::query()->firstOrCreate(['code' => 'support-local'], [
            'name' => 'Support Local',
            'currency' => 'USD',
            'billing_interval' => 'month',
            'trial_period_days' => 0,
            'grace_period_days' => 7,
            'enabled' => true,
            'display_order' => 999,
        ]);
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
