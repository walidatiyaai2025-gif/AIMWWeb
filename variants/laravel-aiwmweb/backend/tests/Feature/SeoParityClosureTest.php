<?php

namespace Tests\Feature;

use App\AI\Platform\Contracts\AiGenerator;
use App\Authorization\TenantAuthorizer;
use App\Connector\ConnectorScopePolicy;
use App\Connector\WordPressGateway;
use App\Execution\ExecutionCreator;
use App\Jobs\ExecuteApprovedSuggestionJob;
use App\Models\Approval;
use App\Models\Connector;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\SeoManagerService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class SeoParityClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_persists_normalized_findings_progress_and_readability(): void
    {
        [$tenant, $membership] = $this->tenant('audit');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Audit', 'url' => 'https://audit.test']);
        $content = $this->content($site, ['title' => '', 'seo_title' => '', 'seo_description' => '', 'content' => 'Tiny copy.', 'seo_provider' => 'yoast-seo']);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $membership->user_id]);
        $seo = new SeoManagerService(new SeoFakeWordPressGateway($content->toArray()));

        $seo->runAudit($audit);

        $audit->refresh();
        $this->assertSame('succeeded', $audit->status);
        $this->assertSame(1, $audit->total_items);
        $this->assertSame(1, $audit->processed_items);
        $this->assertNotEmpty($audit->log);
        $this->assertNotNull($content->fresh()->seo_source_hash);
        $this->assertNotNull($content->fresh()->seo_readability_score);
        $this->assertTrue(SeoFinding::query()->where('code', 'missing_title')->exists());
        $this->assertTrue(SeoFinding::query()->where('code', 'missing_meta_description')->exists());
    }

    public function test_connector_scope_policy_requires_seo_write_for_all_plugin_metadata(): void
    {
        $policy = new ConnectorScopePolicy;
        $payload = ['changes' => ['seo_title' => 'T', 'seo_description' => 'D', 'seo_canonical' => 'https://example.test/p', 'seo_robots' => ['index', 'follow']]];
        $this->assertSame(['seo.write'], $policy->requiredFor('content.execute', $payload));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required connector scope is disabled: seo.write.');
        $policy->assertAuthorized('content.execute', $payload, ['content.update']);
    }

    public function test_seo_write_permission_is_fail_closed(): void
    {
        [$tenant, $membership] = $this->tenant('permission');
        app(TenantContext::class)->activate($tenant, $membership);
        $authorizer = app(TenantAuthorizer::class);
        try {
            $authorizer->authorize('seo.write');
            $this->fail('seo.write was granted without an assigned permission.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $role = Role::query()->create(['name' => 'seo-editor']);
        $permission = Permission::query()->create(['name' => 'seo.write']);
        $role->permissions()->attach($permission->id, ['tenant_id' => $tenant->id]);
        $membership->roles()->attach($role->id, ['tenant_id' => $tenant->id]);
        $authorizer->authorize('seo.write');
        $this->assertTrue(true);
    }

    public function test_yoast_rank_math_and_unsupported_provider_truthfulness(): void
    {
        $seo = new SeoManagerService(new SeoFakeWordPressGateway([]));
        $this->assertSame('SUPPORTED_ENABLED', $seo->providerState('yoast-seo')['state']);
        $this->assertContains('seo_canonical', $seo->providerState('yoast-seo')['writable']);
        $this->assertSame('SUPPORTED_ENABLED', $seo->providerState('rank-math')['state']);
        $this->assertContains('seo_robots', $seo->providerState('rank-math')['writable']);
        $this->assertSame('WORDPRESS_NATIVE', $seo->providerState(null)['state']);
        $this->assertSame(['title', 'slug'], $seo->providerState(null)['writable']);
        $this->assertSame('UNSUPPORTED', $seo->providerState('all-in-one-seo')['state']);
    }

    public function test_unsupported_plugin_refuses_plugin_metadata_remediation(): void
    {
        [$tenant, $membership] = $this->tenant('unsupported');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Unsupported', 'url' => 'https://unsupported.test']);
        $content = $this->content($site, ['seo_provider' => 'all-in-one-seo']);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $membership->user_id]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'missing_meta_description',
            'field' => 'seo_description',
            'severity' => 'high',
            'recommendation' => 'Add description',
            'suggested_value' => 'Safe description',
        ]);
        $seo = new SeoManagerService(new SeoFakeWordPressGateway($content->toArray()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEO plugin metadata write is unsupported');
        $seo->prepareRemediation($finding, $membership->user_id);
    }

    public function test_stale_state_blocks_mutation_and_persists_failed_evidence(): void
    {
        [$tenant, $membership] = $this->tenant('stale');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Stale', 'url' => 'https://stale.test']);
        $content = $this->content($site, ['title' => 'Approved snapshot', 'seo_provider' => 'yoast-seo']);
        [$approval, $execution] = $this->approvedExecution($site, $content, $membership->user_id, ['seo_title' => 'New SEO'], $content->toArray());
        $remote = $content->toArray();
        $remote['title'] = 'Changed in WordPress';
        $remote['type'] = 'post';
        $remote['id'] = 41;
        $gateway = new SeoFakeWordPressGateway($remote);
        $seo = new SeoManagerService($gateway);

        try {
            (new ExecuteApprovedSuggestionJob($tenant->id, $execution->id))->handle($gateway, $seo);
            $this->fail('Stale state was mutated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('STALE_WORDPRESS_STATE', $exception->getMessage());
        }

        $this->assertSame(0, $gateway->mutationCount);
        $this->assertSame('failed', $execution->fresh()->status);
        $this->assertFalse(EvidenceReceipt::query()->where('execution_id', $execution->id)->firstOrFail()->verified);
        $this->assertSame('APPROVED', $approval->status);
    }

    public function test_reread_verification_idempotency_and_evidence_cover_full_metadata(): void
    {
        [$tenant, $membership] = $this->tenant('verify');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Verify', 'url' => 'https://verify.test']);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => fake()->uuid(),
            'encrypted_secret' => 'server-only-secret',
            'capabilities' => ['content.read', 'seo.write'],
            'enabled_scopes' => ['content.read', 'seo.write'],
        ]);
        $content = $this->content($site, ['seo_provider' => 'yoast-seo']);
        $before = $content->toArray() + ['type' => 'post', 'id' => 41, 'modified_at' => '2026-08-27T00:00:00+00:00'];
        $changes = [
            'seo_title' => 'Verified title',
            'seo_description' => 'Verified description',
            'seo_canonical' => 'https://verify.test/canonical',
            'seo_robots' => ['follow', 'index'],
        ];
        [$approval] = $this->approvedExecution($site, $content, $membership->user_id, $changes, $before, false);
        [$execution, $created] = app(ExecutionCreator::class)->create($approval, $membership->user_id);
        [$duplicate, $duplicateCreated] = app(ExecutionCreator::class)->create($approval, $membership->user_id);
        $this->assertTrue($created);
        $this->assertFalse($duplicateCreated);
        $this->assertSame($execution->id, $duplicate->id);

        $gateway = new SeoFakeWordPressGateway($before);
        $seo = new SeoManagerService($gateway);
        (new ExecuteApprovedSuggestionJob($tenant->id, $execution->id))->handle($gateway, $seo);
        (new ExecuteApprovedSuggestionJob($tenant->id, $execution->id))->handle($gateway, $seo);

        $receipt = EvidenceReceipt::query()->where('execution_id', $execution->id)->firstOrFail();
        $this->assertSame(1, $gateway->mutationCount);
        $this->assertSame('succeeded', $execution->fresh()->status);
        $this->assertTrue($receipt->verified);
        $this->assertSame('https://verify.test/canonical', $receipt->actual_after_state['seo_canonical']);
        $this->assertSame(['follow', 'index'], $receipt->actual_after_state['seo_robots']);
    }

    public function test_bulk_preparation_reports_partial_failure_without_bypassing_approval(): void
    {
        [$tenant, $membership] = $this->tenant('bulk');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Bulk', 'url' => 'https://bulk.test']);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $membership->user_id]);
        $supported = $this->content($site, ['remote_id' => 41, 'seo_provider' => 'rank-math']);
        $unsupported = $this->content($site, ['remote_id' => 42, 'seo_provider' => 'all-in-one-seo']);
        $findingA = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $supported->id, 'code' => 'a', 'field' => 'seo_title', 'severity' => 'high', 'recommendation' => 'fix', 'suggested_value' => 'A']);
        $findingB = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $unsupported->id, 'code' => 'b', 'field' => 'seo_title', 'severity' => 'high', 'recommendation' => 'fix', 'suggested_value' => 'B']);
        $seo = new SeoManagerService(new SeoFakeWordPressGateway([]));

        $result = $seo->prepareBulk([
            ['finding_id' => $findingA->id],
            ['finding_id' => $findingB->id],
        ], $membership->user_id);

        $this->assertCount(1, $result['prepared']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame('PENDING', Approval::query()->findOrFail($result['prepared'][0]['approval_id'])->status);
        $this->assertSame(0, Execution::query()->count());
    }

    public function test_retry_reuses_same_operation_and_replaces_failed_evidence_after_success(): void
    {
        [$tenant, $membership] = $this->tenant('retry');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Retry', 'url' => 'https://retry.test']);
        $content = $this->content($site, ['seo_provider' => 'yoast-seo']);
        $before = $content->toArray() + ['type' => 'post', 'id' => 41, 'modified_at' => '2026-08-27T00:00:00+00:00'];
        [, $execution] = $this->approvedExecution($site, $content, $membership->user_id, ['seo_title' => 'Retry title'], $before);
        $gateway = new SeoFakeWordPressGateway($before);
        $gateway->failNextMutation = true;
        $seo = new SeoManagerService($gateway);

        try {
            (new ExecuteApprovedSuggestionJob($tenant->id, $execution->id))->handle($gateway, $seo);
        } catch (RuntimeException) {
        }
        $this->assertTrue($seo->retryable($execution->fresh()));
        $operationId = $execution->operation_id;
        $execution->update(['status' => 'queued', 'failure' => null, 'completed_at' => null]);
        (new ExecuteApprovedSuggestionJob($tenant->id, $execution->id))->handle($gateway, $seo);

        $this->assertSame($operationId, $execution->fresh()->operation_id);
        $this->assertSame(2, $execution->fresh()->attempts);
        $this->assertTrue(EvidenceReceipt::query()->where('execution_id', $execution->id)->firstOrFail()->verified);
    }

    public function test_ai_phase_consumes_pr_267_generator_contract_without_publishing(): void
    {
        [$tenant, $membership] = $this->tenant('ai');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'AI', 'url' => 'https://ai.test']);
        $content = $this->content($site, ['seo_provider' => 'yoast-seo']);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $membership->user_id]);
        $finding = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $content->id, 'code' => 'ai', 'field' => 'seo_title', 'severity' => 'medium', 'recommendation' => 'Improve']);
        $this->app->instance(AiGenerator::class, new SeoFakeAiGenerator);
        $seo = new SeoManagerService(new SeoFakeWordPressGateway($content->toArray()));

        $proposal = $seo->generateAiProposal($finding, $site->id);

        $this->assertTrue($proposal['requires_approval']);
        $this->assertSame('AI SEO title', $proposal['proposal']['seo_title']);
        $this->assertSame(0, Execution::query()->count());
    }

    public function test_seo_records_remain_tenant_isolated(): void
    {
        [$tenantA, $memberA] = $this->tenant('tenant-a');
        [$tenantB, $memberB] = $this->tenant('tenant-b');
        app(TenantContext::class)->activate($tenantB, $memberB);
        $site = Site::query()->create(['name' => 'B', 'url' => 'https://b.test']);
        $content = $this->content($site);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $memberB->user_id]);
        $finding = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $content->id, 'code' => 'private', 'severity' => 'low', 'recommendation' => 'private']);

        app(TenantContext::class)->activate($tenantA, $memberA);
        $this->assertNull(SeoAudit::query()->find($audit->id));
        $this->assertNull(SeoFinding::query()->find($finding->id));
        $this->assertNull(SyncedContent::query()->find($content->id));
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

    private function content(Site $site, array $overrides = []): SyncedContent
    {
        return SyncedContent::query()->create(array_merge([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 41,
            'slug' => 'seo-page',
            'title' => 'SEO page',
            'content' => str_repeat('Readable sentence with useful words. ', 20),
            'excerpt' => 'Useful excerpt.',
            'headings' => ['SEO page'],
            'taxonomy' => [],
            'media' => [],
            'seo_title' => 'SEO page title',
            'seo_description' => 'A useful SEO description for this page.',
            'seo_provider' => 'yoast-seo',
            'seo_canonical' => 'https://example.test/seo-page',
            'seo_robots' => ['index', 'follow'],
            'remote_modified_at' => '2026-08-27T00:00:00+00:00',
        ], $overrides));
    }

    private function approvedExecution(Site $site, SyncedContent $content, int $actorUserId, array $changes, array $before, bool $createExecution = true): array
    {
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $actorUserId]);
        $finding = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $content->id, 'code' => fake()->unique()->lexify('code-????'), 'severity' => 'high', 'recommendation' => 'Fix']);
        $suggestion = Suggestion::query()->create(['site_id' => $site->id, 'seo_finding_id' => $finding->id, 'synced_content_id' => $content->id, 'actor_user_id' => $actorUserId, 'status' => 'ready', 'before_state' => $before, 'proposed_state' => $changes]);
        $approval = Approval::query()->create(['suggestion_id' => $suggestion->id, 'actor_user_id' => $actorUserId, 'status' => 'APPROVED', 'before_state' => $before, 'proposed_state' => $changes, 'decided_at' => now()]);
        if (! $createExecution) {
            return [$approval, null];
        }
        [$execution] = app(ExecutionCreator::class)->create($approval, $actorUserId);

        return [$approval, $execution];
    }
}

final class SeoFakeAiGenerator implements AiGenerator
{
    public function generate(array $request): array
    {
        return [
            'correlation_id' => 'seo-ai-correlation',
            'provider' => 'fake-pr267',
            'model' => 'fake-model',
            'content' => '{"seo_title":"AI SEO title"}',
            'structured' => ['seo_title' => 'AI SEO title', 'seo_description' => 'AI SEO description'],
        ];
    }
}

final class SeoFakeWordPressGateway implements WordPressGateway
{
    public int $mutationCount = 0;

    public bool $failNextMutation = false;

    public function __construct(public array $remote) {}

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
        if ($this->failNextMutation) {
            $this->failNextMutation = false;
            throw new RuntimeException('Simulated WordPress mutation failure.');
        }
        $this->mutationCount++;
        foreach ((array) ($change['changes'] ?? []) as $key => $value) {
            $this->remote[$key] = $value;
        }

        return ['operation_id' => $operationId, 'status' => 'succeeded', 'after' => $this->remote];
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
