<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Models\BillingAudit;
use App\Models\BillingPlan;
use App\Models\BillingTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BillingHistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_sql_pages_preserve_totals_tenant_isolation_and_never_expose_provider_secrets(): void
    {
        [$alpha, $owner] = $this->tenant('alpha', ['tenant.view', 'billing.view']);
        [$beta, $foreign] = $this->tenant('beta', ['tenant.view', 'billing.view']);
        $context = app(TenantContext::class);
        $plan = BillingPlan::query()->where('code', 'pro')->firstOrFail();

        $context->activate($alpha, $owner);
        $subscription = TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'manual',
            'started_at' => now(),
        ]);

        for ($index = 0; $index < 110; $index++) {
            BillingAudit::query()->create([
                'action' => 'event-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'metadata' => ['password' => 'ALPHA_METADATA_SECRET'],
                'occurred_at' => now()->subMinutes($index + 1),
            ]);
        }
        for ($index = 0; $index < 31; $index++) {
            BillingTransaction::query()->create([
                'tenant_subscription_id' => $subscription->id,
                'provider' => 'manual',
                'encrypted_provider_transaction_id' => 'ALPHA_PROVIDER_SECRET',
                'type' => 'charge',
                'status' => 'confirmed',
                'amount_minor' => $index + 1,
                'currency' => 'KWD',
                'occurred_at' => now()->subMinutes($index + 1),
                'metadata' => ['api_key' => 'ALPHA_TX_SECRET'],
            ]);
        }
        $context->activate($beta, $foreign);
        BillingAudit::query()->create([
            'action' => 'OTHER_TENANT_PRIVATE',
            'metadata' => ['api_key' => 'BETA_PRIVATE'],
            'occurred_at' => now(),
        ]);
        $context->forget();

        $response = $this->actingAs($owner->user)
            ->getJson('/api/v1/tenants/alpha/billing/history/paged?audit_page=2&transaction_page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('pagination.audit.page', 2)
            ->assertJsonPath('pagination.audit.total', 110)
            ->assertJsonPath('pagination.audit.last_page', 11)
            ->assertJsonPath('pagination.audit.from', 11)
            ->assertJsonPath('pagination.audit.to', 20)
            ->assertJsonPath('pagination.transactions.page', 1)
            ->assertJsonPath('pagination.transactions.total', 31)
            ->assertJsonPath('pagination.transactions.last_page', 4)
            ->assertJsonCount(10, 'data.audit')
            ->assertJsonCount(10, 'data.transactions')
            ->assertJsonPath('data.audit.0.action', 'event-010')
            ->assertJsonPath('data.transactions.0.amount_minor', 1);

        foreach (['ALPHA_METADATA_SECRET', 'ALPHA_PROVIDER_SECRET', 'ALPHA_TX_SECRET', 'OTHER_TENANT_PRIVATE', 'BETA_PRIVATE', 'encrypted_provider_transaction_id', 'metadata'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $last = $this->actingAs($owner->user)
            ->getJson('/api/v1/tenants/alpha/billing/history/paged?audit_page=1000000&transaction_page=1000000&per_page=10')
            ->assertOk()
            ->assertJsonPath('pagination.audit.page', 11)
            ->assertJsonPath('pagination.audit.from', 101)
            ->assertJsonPath('pagination.transactions.page', 4)
            ->assertJsonCount(10, 'data.audit')
            ->assertJsonCount(1, 'data.transactions');
        $this->assertSame('event-100', $last->json('data.audit.0.action'));

        // Preserve the legacy response envelope for existing API consumers.
        $legacy = $this->actingAs($owner->user)->getJson('/api/v1/tenants/alpha/billing/history')->assertOk()
            ->assertJsonCount(100, 'data.audit')->assertJsonCount(31, 'data.transactions');
        $this->assertArrayNotHasKey('pagination', $legacy->json());

        $this->actingAs($foreign->user)
            ->getJson('/api/v1/tenants/alpha/billing/history/paged')
            ->assertNotFound();
    }

    public function test_validation_permissions_and_genuine_empty_state(): void
    {
        [$tenant, $owner] = $this->tenant('empty', ['tenant.view', 'billing.view']);
        [, $viewer] = $this->tenant('no-billing', ['tenant.view']);

        $this->getJson('/api/v1/tenants/empty/billing/history/paged')->assertUnauthorized();
        $this->actingAs($viewer->user)
            ->getJson('/api/v1/tenants/no-billing/billing/history/paged')
            ->assertForbidden();

        foreach (['audit_page=0', 'transaction_page=1000001', 'per_page=999', 'audit_page=untrusted'] as $bad) {
            $this->actingAs($owner->user)
                ->getJson('/api/v1/tenants/empty/billing/history/paged?'.$bad)
                ->assertUnprocessable();
        }
        $this->actingAs($owner->user)
            ->getJson('/api/v1/tenants/empty/billing/history/paged')
            ->assertOk()
            ->assertJsonCount(0, 'data.audit')
            ->assertJsonCount(0, 'data.transactions')
            ->assertJsonPath('pagination.audit.page', 1)
            ->assertJsonPath('pagination.audit.total', 0)
            ->assertJsonPath('pagination.transactions.total', 0);
        $this->assertSame('empty', $tenant->slug);
    }

    /** @param list<string> $permissions
     *  @return array{0:Tenant,1:TenantMembership}
     */
    private function tenant(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'billing-history-'.$slug]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }
}
