<?php

namespace Tests\Feature;

use App\AI\AiProvider;
use App\Connector\ConnectorProtocol;
use App\Connector\ConnectorScopePolicy;
use App\Connector\WordPressGateway;
use App\Execution\ExecutionCreator;
use App\Jobs\ExecuteApprovedSuggestionJob;
use App\Jobs\GenerateSuggestionJob;
use App\Jobs\RunSeoAuditJob;
use App\Jobs\SyncSiteJob;
use App\Models\AiProviderConfig;
use App\Models\Approval;
use App\Models\Connector;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DemoVerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_approved_change_executes_and_is_verified_by_reread(): void
    {
        [$tenant, $membership] = $this->tenant('alpha');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Alpha WP', 'url' => 'https://alpha.test']);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => fake()->uuid(),
            'encrypted_secret' => 'server-only-secret',
            'capabilities' => ['health', 'content.read', 'content.update'],
            'enabled_scopes' => ['health', 'content.read', 'content.update'],
        ]);
        AiProviderConfig::query()->create([
            'provider' => 'test', 'endpoint' => 'https://ai.test', 'model' => 'test-model',
            'encrypted_api_key' => 'server-only-api-key', 'enabled' => true,
        ]);

        $gateway = new InMemoryWordPressGateway;
        $this->app->instance(WordPressGateway::class, $gateway);
        $this->app->instance(AiProvider::class, new DeterministicAiProvider);

        $sync = SyncRun::query()->create(['site_id' => $site->id]);
        Bus::dispatchSync(new SyncSiteJob($tenant->id, $site->id, $sync->id));
        $this->assertSame($tenant->id, app(TenantContext::class)->id());
        $content = SyncedContent::query()->firstOrFail();
        $this->assertSame('succeeded', $sync->fresh()->status);

        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $membership->user_id]);
        Bus::dispatchSync(new RunSeoAuditJob($tenant->id, $audit->id));
        $this->assertSame($tenant->id, app(TenantContext::class)->id());
        $finding = SeoFinding::query()->firstOrFail();

        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id, 'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id, 'actor_user_id' => $membership->user_id,
            'before_state' => $content->only(['slug', 'title', 'content', 'seo_title', 'seo_description']),
        ]);
        Bus::dispatchSync(new GenerateSuggestionJob($tenant->id, $suggestion->id));
        $this->assertSame($tenant->id, app(TenantContext::class)->id());
        $approval = Approval::query()->firstOrFail();
        $this->assertSame('PENDING', $approval->status);
        $approval->update(['status' => 'APPROVED', 'decided_at' => now()]);

        [$execution, $created] = app(ExecutionCreator::class)->create($approval, $membership->user_id);
        [$duplicate, $duplicateCreated] = app(ExecutionCreator::class)->create($approval, $membership->user_id);
        $this->assertTrue($created);
        $this->assertFalse($duplicateCreated);
        $this->assertSame($execution->id, $duplicate->id);
        $this->assertSame($execution->operation_id, $duplicate->operation_id);
        $this->assertSame(1, Execution::query()->where('approval_id', $approval->id)->count());
        try {
            Execution::query()->create([
                'operation_id' => fake()->uuid(), 'request_id' => fake()->uuid(), 'correlation_id' => fake()->uuid(),
                'site_id' => $site->id, 'approval_id' => $approval->id, 'actor_user_id' => $membership->user_id,
            ]);
            $this->fail('Database accepted a second execution for one approval.');
        } catch (QueryException) {
            $this->assertSame(1, Execution::query()->where('approval_id', $approval->id)->count());
        }
        Bus::dispatchSync(new ExecuteApprovedSuggestionJob($tenant->id, $execution->id));
        $this->assertSame($tenant->id, app(TenantContext::class)->id());
        Bus::dispatchSync(new ExecuteApprovedSuggestionJob($tenant->id, $execution->id));
        $this->assertSame($tenant->id, app(TenantContext::class)->id());

        $receipt = EvidenceReceipt::query()->firstOrFail();
        $this->assertSame('succeeded', $execution->fresh()->status);
        $this->assertTrue($receipt->verified);
        $this->assertSame('A useful title', $receipt->actual_after_state['title']);
        $this->assertSame('A useful title', $gateway->remote['title']);
        $this->assertSame($execution->operation_id, $receipt->operation_id);
        $this->assertSame(1, $gateway->mutationCount);
        $this->assertArrayNotHasKey('encrypted_secret', Connector::query()->firstOrFail()->toArray());
        $this->assertArrayNotHasKey('encrypted_api_key', AiProviderConfig::query()->firstOrFail()->toArray());
        app(TenantContext::class)->forget();
    }

    public function test_every_vertical_slice_resource_rejects_cross_tenant_guessed_ids(): void
    {
        [$tenantA, $memberA] = $this->tenant('alpha');
        [$tenantB, $memberB] = $this->tenant('beta');
        $context = app(TenantContext::class);
        $context->activate($tenantB, $memberB);
        $site = Site::query()->create(['name' => 'Beta WP', 'url' => 'https://beta.test']);
        $connector = Connector::query()->create(['site_id' => $site->id, 'identity' => fake()->uuid(), 'encrypted_secret' => 'secret', 'capabilities' => [], 'enabled_scopes' => []]);
        $sync = SyncRun::query()->create(['site_id' => $site->id]);
        $content = SyncedContent::query()->create(['site_id' => $site->id, 'resource_type' => 'post', 'remote_id' => 9, 'slug' => 'beta']);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $memberB->user_id]);
        $finding = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $content->id, 'code' => 'title_missing', 'severity' => 'high', 'recommendation' => 'Add title']);
        $ai = AiProviderConfig::query()->create(['provider' => 'beta', 'endpoint' => 'https://ai.test', 'model' => 'm', 'encrypted_api_key' => 'secret']);
        $suggestion = Suggestion::query()->create(['site_id' => $site->id, 'seo_finding_id' => $finding->id, 'synced_content_id' => $content->id, 'actor_user_id' => $memberB->user_id, 'before_state' => [], 'proposed_state' => ['title' => 'B']]);
        $approval = Approval::query()->create(['suggestion_id' => $suggestion->id, 'actor_user_id' => $memberB->user_id, 'before_state' => [], 'proposed_state' => ['title' => 'B']]);
        $execution = Execution::query()->create(['operation_id' => fake()->uuid(), 'request_id' => fake()->uuid(), 'correlation_id' => fake()->uuid(), 'site_id' => $site->id, 'approval_id' => $approval->id, 'actor_user_id' => $memberB->user_id]);
        $receipt = EvidenceReceipt::query()->create(['site_id' => $site->id, 'execution_id' => $execution->id, 'actor_user_id' => $memberB->user_id, 'operation_id' => $execution->operation_id, 'request_id' => $execution->request_id, 'correlation_id' => $execution->correlation_id, 'before_state' => [], 'proposed_state' => [], 'verified' => false]);

        $ids = [[Site::class, $site->id], [Connector::class, $connector->id], [SyncRun::class, $sync->id], [SyncedContent::class, $content->id], [SeoAudit::class, $audit->id], [SeoFinding::class, $finding->id], [AiProviderConfig::class, $ai->id], [Suggestion::class, $suggestion->id], [Approval::class, $approval->id], [Execution::class, $execution->id], [EvidenceReceipt::class, $receipt->id]];
        $context->activate($tenantA, $memberA);
        foreach ($ids as [$class, $id]) {
            $this->assertNull($class::query()->find($id), "{$class} leaked across tenants");
        }
        try {
            Site::query()->findOrFail($site->id)->update(['name' => 'Alpha overwrite']);
            $this->fail('Cross-tenant update was not rejected.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $context->activate($tenantB, $memberB);
        $this->assertSame('Beta WP', Site::query()->findOrFail($site->id)->name);
        $this->assertSame(1, Site::query()->count());
    }

    public function test_connector_protocol_rejects_tampering_expiry_replay_scope_and_revocation(): void
    {
        [$tenant, $membership] = $this->tenant('alpha');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Alpha WP', 'url' => 'https://alpha.test']);
        $connector = Connector::query()->create([
            'site_id' => $site->id, 'identity' => fake()->uuid(), 'encrypted_secret' => 'shared-secret',
            'capabilities' => ['health'], 'enabled_scopes' => ['health'],
        ]);
        $protocol = app(ConnectorProtocol::class);
        $tampered = $protocol->sign($connector, 'GET', '/wp-json/aimw/v1/health', '', 'health');
        $tampered['X-AIMW-Signature'] = str_repeat('0', 64);
        $this->assertProtocolRejected(fn () => $protocol->verifyInbound($connector, 'GET', '/wp-json/aimw/v1/health', '', $tampered), 'Invalid connector signature.');
        $expired = $protocol->sign($connector, 'GET', '/wp-json/aimw/v1/health', '', 'health');
        $expired['X-AIMW-Timestamp'] = (string) now()->subMinutes(6)->timestamp;
        $this->assertProtocolRejected(fn () => $protocol->verifyInbound($connector, 'GET', '/wp-json/aimw/v1/health', '', $expired), 'Connector timestamp expired.');
        $disabled = $protocol->sign($connector, 'GET', '/wp-json/aimw/v1/health', '', 'health');
        $disabled['X-AIMW-Scope'] = 'content.update';
        $this->assertProtocolRejected(fn () => $protocol->verifyInbound($connector, 'GET', '/wp-json/aimw/v1/health', '', $disabled), 'Connector scope is disabled.');
        $headers = $protocol->sign($connector, 'GET', '/wp-json/aimw/v1/health', '', 'health');
        $protocol->verifyInbound($connector, 'GET', '/wp-json/aimw/v1/health', '', $headers);
        try {
            $protocol->verifyInbound($connector, 'GET', '/wp-json/aimw/v1/health', '', $headers);
            $this->fail('Nonce replay was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $connector->update(['revoked_at' => now()]);
        $revoked = $protocol->sign($connector, 'GET', '/wp-json/aimw/v1/health', '', 'health');
        $this->assertProtocolRejected(fn () => $protocol->verifyInbound($connector, 'GET', '/wp-json/aimw/v1/health', '', $revoked), 'Connector is revoked.');
    }

    public function test_operations_are_bound_to_server_required_scopes(): void
    {
        $policy = app(ConnectorScopePolicy::class);
        $this->assertSame(['health'], $policy->requiredFor('health'));
        $this->assertSame(['content.read'], $policy->requiredFor('content.read'));
        $this->assertSame(['connector.manage'], $policy->requiredFor('connector.rotate'));
        $this->assertProtocolRejected(
            fn () => $policy->assertAuthorized('content.execute', ['changes' => ['title' => 'mutate']], ['health']),
            'Required connector scope is disabled: content.update.'
        );
        $this->assertProtocolRejected(
            fn () => $policy->assertAuthorized('content.execute', ['changes' => ['title' => 'mutate']], ['content.read']),
            'Required connector scope is disabled: content.update.'
        );
        $this->assertProtocolRejected(
            fn () => $policy->assertAuthorized('content.execute', ['changes' => ['seo_title' => 'SEO']], ['content.update']),
            'Required connector scope is disabled: seo.write.'
        );
        $this->assertProtocolRejected(
            fn () => $policy->assertAuthorized('content.read', [], []),
            'Required connector scope is disabled: content.read.'
        );
    }

    private function tenant(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $membership->setRelation('user', $user);
        app(TenantContext::class)->forget();

        return [$tenant, $membership];
    }

    private function assertProtocolRejected(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Unsafe connector request was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}

final class DeterministicAiProvider implements AiProvider
{
    public function suggest(AiProviderConfig $config, array $content, array $finding): array
    {
        return ['title' => 'A useful title', 'seo_description' => 'A useful description'];
    }
}

final class InMemoryWordPressGateway implements WordPressGateway
{
    public int $mutationCount = 0;

    public array $remote = ['type' => 'post', 'id' => 42, 'slug' => 'hello', 'title' => '', 'content' => 'short', 'excerpt' => '', 'headings' => [], 'taxonomy' => [], 'media' => [], 'seo_title' => '', 'seo_description' => '', 'modified_at' => '2026-08-27T00:00:00+00:00'];

    public function health(Site $site): array
    {
        return ['status' => 'healthy'];
    }

    public function content(Site $site, ?string $modifiedAfter = null): array
    {
        return ['items' => [$this->remote]];
    }

    public function execute(Site $site, string $operationId, array $change): array
    {
        $this->mutationCount++;
        $this->remote = array_replace($this->remote, $change['changes']);

        return ['operation_id' => $operationId, 'status' => 'succeeded'];
    }

    public function read(Site $site, string $type, int $remoteId): array
    {
        return $this->remote;
    }

    public function rotateSecret(Site $site, string $newSecret): array
    {
        return ['rotated' => true];
    }

    public function disconnect(Site $site): array
    {
        return ['disconnected' => true];
    }
}
