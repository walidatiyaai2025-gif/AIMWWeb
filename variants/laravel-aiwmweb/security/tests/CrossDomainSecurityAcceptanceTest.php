<?php

namespace Tests\Feature\Security;

use App\Jobs\TenantAwareJob;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSecret;
use App\Models\User;
use App\Tenancy\IdempotencyService;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CrossDomainSecurityAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_tenant_owned_domain_models_keep_the_shared_tenant_scope_contract(): void
    {
        $models = [
            'App\\Models\\Site',
            'App\\Models\\Connector',
            'App\\Models\\ConnectorPairing',
            'App\\Models\\SyncRun',
            'App\\Models\\SyncedContent',
            'App\\Models\\SeoAudit',
            'App\\Models\\SeoFinding',
            'App\\Models\\Suggestion',
            'App\\Models\\Approval',
            'App\\Models\\Execution',
            'App\\Models\\EvidenceReceipt',
            'App\\Models\\AiProviderConfig',
            'App\\Models\\ContentItem',
            'App\\Models\\ContentRevision',
            'App\\Models\\MediaItem',
            'App\\Models\\Comment',
            'App\\Models\\TaxonomyTerm',
            'App\\Models\\ContentSyncState',
            'App\\Models\\ContentConflict',
            'App\\Models\\ContentTransfer',
            'App\\Models\\TenantBillingProfile',
            'App\\Models\\TenantSubscription',
            'App\\Models\\TenantUsageCounter',
            'App\\Models\\BillingAudit',
            'App\\Models\\BillingTransaction',
            'App\\Models\\BillingSubscriptionChange',
            'App\\Models\\AiProviderProfile',
            'App\\Models\\AiModelProfile',
            'App\\Models\\AiPromptTemplate',
            'App\\Models\\AiPromptRevision',
            'App\\Models\\AiGenerationRecord',
            'App\\Models\\AiUsageRecord',
            'App\\Models\\AiPlannerItem',
            'App\\Models\\AiPlannerHistory',
            'App\\Models\\SiteDiagnostic',
            'App\\Models\\SiteOperationHistory',
        ];

        $present = 0;
        foreach ($models as $model) {
            if (! class_exists($model)) {
                continue;
            }
            $present++;
            $this->assertArrayHasKey(
                BelongsToTenant::class,
                class_uses_recursive($model),
                $model.' must use the canonical tenant scope contract.',
            );
        }

        $this->assertGreaterThanOrEqual(1, $present, 'At least Tenant Core/domain models must be present.');
    }

    public function test_cache_locks_idempotency_and_queue_context_are_partitioned_between_tenants(): void
    {
        [$tenantA, $memberA] = $this->tenantWithPermissions('security-alpha', ['tenant.view']);
        [$tenantB, $memberB] = $this->tenantWithPermissions('security-beta', ['tenant.view']);
        $context = app(TenantContext::class);

        $context->activate($tenantA, $memberA);
        $cacheA = app(TenantCache::class)->key('cross-domain:shared-key');
        $idempotentA = app(IdempotencyService::class)->run(
            'same-request-key',
            'cross-domain-security',
            ['same' => 'payload'],
            fn () => ['tenant' => 'A'],
        );
        $lockA = app(TenantCache::class)->key('lock:cross-domain');
        $this->assertSame('A', app(TenantLock::class)->block('cross-domain', 1, fn () => 'A'));

        $context->activate($tenantB, $memberB);
        $cacheB = app(TenantCache::class)->key('cross-domain:shared-key');
        $idempotentB = app(IdempotencyService::class)->run(
            'same-request-key',
            'cross-domain-security',
            ['same' => 'payload'],
            fn () => ['tenant' => 'B'],
        );
        $lockB = app(TenantCache::class)->key('lock:cross-domain');
        $this->assertSame('B', app(TenantLock::class)->block('cross-domain', 1, fn () => 'B'));

        $this->assertNotSame($cacheA, $cacheB);
        $this->assertNotSame($lockA, $lockB);
        $this->assertSame(['tenant' => 'A'], $idempotentA);
        $this->assertSame(['tenant' => 'B'], $idempotentB);

        $context->forget();
        SecurityObservingTenantJob::$observedTenantIds = [];
        Bus::dispatchSync(new SecurityObservingTenantJob($tenantA->id));
        $this->assertFalse($context->active(), 'Tenant A queue context leaked after execution.');
        Bus::dispatchSync(new SecurityObservingTenantJob($tenantB->id));
        $this->assertFalse($context->active(), 'Tenant B queue context leaked after execution.');
        $this->assertSame([$tenantA->id, $tenantB->id], SecurityObservingTenantJob::$observedTenantIds);
        $this->assertNotSame(
            (new SecurityObservingTenantJob($tenantA->id))->uniqueId(),
            (new SecurityObservingTenantJob($tenantB->id))->uniqueId(),
        );
    }

    public function test_connector_protocol_rejects_full_adversarial_identity_signature_replay_and_scope_matrix(): void
    {
        if (! class_exists('App\\Connector\\ConnectorProtocol') || ! class_exists('App\\Models\\Site')) {
            $this->markTestSkipped('Connector domain is not present on this candidate head.');
        }

        [$tenantA, $memberA] = $this->tenantWithPermissions('connector-alpha', ['tenant.view']);
        [$tenantB, $memberB] = $this->tenantWithPermissions('connector-beta', ['tenant.view']);
        $context = app(TenantContext::class);
        $context->activate($tenantA, $memberA);

        $siteClass = 'App\\Models\\Site';
        $connectorClass = 'App\\Models\\Connector';
        $protocolClass = 'App\\Connector\\ConnectorProtocol';
        $policyClass = 'App\\Connector\\ConnectorScopePolicy';

        $siteA = $siteClass::query()->create(['name' => 'Alpha WP', 'url' => 'https://alpha.test']);
        $connector = $connectorClass::query()->create([
            'site_id' => $siteA->id,
            'identity' => 'security-connector-alpha',
            'encrypted_secret' => 'old-shared-secret',
            'capabilities' => ['health', 'content.read', 'content.update'],
            'enabled_scopes' => ['health', 'content.read', 'content.update'],
        ]);

        $context->activate($tenantB, $memberB);
        $siteB = $siteClass::query()->create(['name' => 'Beta WP', 'url' => 'https://beta.test']);
        $context->activate($tenantA, $memberA);

        $protocol = app($protocolClass);
        $path = '/wp-json/aimw/v1/health';

        $tampered = $protocol->sign($connector, 'POST', $path, '{"safe":true}', 'health');
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'POST', $path, '{"safe":false}', $tampered),
            'Invalid connector signature.',
        );

        $expired = $protocol->sign($connector, 'GET', $path, '', 'health');
        $expired['X-AIMW-Timestamp'] = (string) now()->subSeconds(301)->timestamp;
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'GET', $path, '', $expired),
            'Connector timestamp expired.',
        );

        $future = $protocol->sign($connector, 'GET', $path, '', 'health');
        $future['X-AIMW-Timestamp'] = (string) now()->addSeconds(301)->timestamp;
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'GET', $path, '', $future),
            'Connector timestamp expired.',
        );

        $wrongTenant = $protocol->sign($connector, 'GET', $path, '', 'health');
        $wrongTenant['X-AIMW-Tenant'] = (string) $tenantB->id;
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'GET', $path, '', $wrongTenant),
            'Connector identity or protocol version mismatch.',
        );

        $wrongSite = $protocol->sign($connector, 'GET', $path, '', 'health');
        $wrongSite['X-AIMW-Site'] = (string) $siteB->id;
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'GET', $path, '', $wrongSite),
            'Connector identity or protocol version mismatch.',
        );

        $replay = $protocol->sign($connector, 'GET', $path, '', 'health');
        $protocol->verifyInbound($connector, 'GET', $path, '', $replay);
        try {
            $protocol->verifyInbound($connector, 'GET', $path, '', $replay);
            $this->fail('Connector nonce replay was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $oldCredential = $protocol->sign($connector, 'GET', $path, '', 'health');
        $connector->update(['encrypted_secret' => 'rotated-new-secret']);
        $connector->refresh();
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'GET', $path, '', $oldCredential),
            'Invalid connector signature.',
        );

        $connector->update(['enabled_scopes' => ['health']]);
        $disabled = $protocol->sign($connector, 'POST', '/wp-json/aimw/v1/content', '{}', 'content.update');
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'POST', '/wp-json/aimw/v1/content', '{}', $disabled),
            'Connector scope is disabled.',
        );

        if (class_exists($policyClass)) {
            $policy = app($policyClass);
            $this->assertRuntimeRejected(
                fn () => $policy->assertAuthorized('content.execute', ['changes' => ['title' => 'x']], ['content.read']),
                'Required connector scope is disabled: content.update.',
            );
            $this->assertRuntimeRejected(
                fn () => $policy->requiredFor('unsupported.security.operation'),
                'Unknown connector operation.',
            );
        }

        $connector->update(['revoked_at' => now(), 'enabled_scopes' => ['health']]);
        $revoked = $protocol->sign($connector, 'GET', $path, '', 'health');
        $this->assertRuntimeRejected(
            fn () => $protocol->verifyInbound($connector, 'GET', $path, '', $revoked),
            'Connector is revoked.',
        );
    }

    public function test_ai_provider_profiles_and_provider_secrets_cannot_cross_tenant_context(): void
    {
        if (! class_exists('App\\Models\\AiProviderProfile') || ! class_exists('App\\AI\\Platform\\Services\\ProviderSecretStore')) {
            $this->markTestSkipped('Advanced AI provider platform is not present on this candidate head.');
        }

        [$tenantA, $memberA] = $this->tenantWithPermissions('ai-alpha', ['tenant.view']);
        [$tenantB, $memberB] = $this->tenantWithPermissions('ai-beta', ['tenant.view']);
        $context = app(TenantContext::class);
        $providerClass = 'App\\Models\\AiProviderProfile';
        $secretStoreClass = 'App\\AI\\Platform\\Services\\ProviderSecretStore';

        $context->activate($tenantB, $memberB);
        $providerB = $providerClass::query()->create([
            'provider_key' => 'beta-provider',
            'adapter_key' => 'openai-compatible',
            'display_name' => 'Beta Provider',
        ]);
        app($secretStoreClass)->put($providerB, 'beta-provider-secret');
        $providerId = $providerB->id;
        $secretName = $providerB->secretName();

        $context->activate($tenantA, $memberA);
        $this->assertNull($providerClass::query()->find($providerId));
        $this->assertNull(TenantSecret::query()->where('name', $secretName)->first());
        $this->assertNull(app($secretStoreClass)->get($providerB));

        $context->activate($tenantB, $memberB);
        $this->assertSame($providerId, $providerClass::query()->findOrFail($providerId)->id);
        $this->assertSame('beta-provider-secret', app($secretStoreClass)->get($providerB));
        $this->assertStringNotContainsString('beta-provider-secret', json_encode($providerB->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_admin_retry_cancel_and_report_download_reject_foreign_tenant_ids(): void
    {
        if (! class_exists('App\\Http\\Controllers\\AdminOperationsController') || ! Schema::hasTable('report_exports')) {
            $this->markTestSkipped('Admin/Operations domain is not present on this candidate head.');
        }

        [, $ownerA] = $this->tenantWithPermissions('admin-alpha', [
            'tenant.view', 'operations.manage', 'reports.manage',
        ]);
        [, $ownerB] = $this->tenantWithPermissions('admin-beta', [
            'tenant.view', 'operations.manage', 'reports.manage',
        ]);

        $export = $this->actingAs($ownerB->user)
            ->postJson('/tenants/admin-beta/admin/reports/exports', ['report_type' => 'operations', 'format' => 'csv'])
            ->assertAccepted()
            ->json();

        $foreignExportId = (int) $export['id'];
        $foreignOperationId = (int) DB::table('report_exports')->where('id', $foreignExportId)->value('operation_execution_id');

        $this->actingAs($ownerA->user)
            ->get("/tenants/admin-alpha/admin/reports/exports/{$foreignExportId}/download")
            ->assertNotFound();
        $this->actingAs($ownerA->user)
            ->postJson("/tenants/admin-alpha/admin/operations/{$foreignOperationId}/retry")
            ->assertNotFound();
        $this->actingAs($ownerA->user)
            ->postJson("/tenants/admin-alpha/admin/operations/{$foreignOperationId}/cancel")
            ->assertNotFound();
    }

    public function test_billing_subscription_and_usage_models_are_not_visible_in_a_foreign_tenant_context(): void
    {
        if (! class_exists('App\\Models\\TenantSubscription') || ! class_exists('App\\Models\\BillingPlan')) {
            $this->markTestSkipped('Billing domain is not present on this candidate head.');
        }

        [$tenantA, $memberA] = $this->tenantWithPermissions('billing-alpha', ['tenant.view']);
        [$tenantB, $memberB] = $this->tenantWithPermissions('billing-beta', ['tenant.view']);
        $context = app(TenantContext::class);
        $subscriptionClass = 'App\\Models\\TenantSubscription';
        $usageClass = 'App\\Models\\TenantUsageCounter';
        $planClass = 'App\\Models\\BillingPlan';

        $context->activate($tenantB, $memberB);
        $plan = $planClass::query()->where('code', 'pro')->firstOrFail();
        $subscription = $subscriptionClass::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => 'ACTIVE',
            'started_at' => now(),
        ]);
        $usage = $usageClass::query()->create([
            'metric' => 'ai.requests.month',
            'period_key' => now()->format('Y-m'),
            'amount_used' => 17,
            'limit_snapshot' => 3000,
            'period_started_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);

        $context->activate($tenantA, $memberA);
        $this->assertNull($subscriptionClass::query()->find($subscription->id));
        $this->assertNull($usageClass::query()->find($usage->id));

        $context->activate($tenantB, $memberB);
        $state = $subscriptionClass::query()->findOrFail($subscription->id)->state;
        $this->assertSame('ACTIVE', $state instanceof \BackedEnum ? $state->value : (string) $state);
        $this->assertSame(17, (int) $usageClass::query()->findOrFail($usage->id)->amount_used);
    }

    private function tenantWithPermissions(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'security-'.$slug]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    private function assertRuntimeRejected(callable $attempt, string $expectedMessage): void
    {
        try {
            $attempt();
            $this->fail('Adversarial connector request was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }
}

final class SecurityObservingTenantJob extends TenantAwareJob
{
    /** @var list<int> */
    public static array $observedTenantIds = [];

    public function handle(TenantContext $context): void
    {
        self::$observedTenantIds[] = $context->id();
    }
}
