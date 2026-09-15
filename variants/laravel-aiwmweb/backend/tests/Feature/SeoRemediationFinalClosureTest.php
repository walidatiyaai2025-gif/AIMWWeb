<?php

namespace Tests\Feature;

use App\Connector\WordPressGateway;
use App\Models\Approval;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\SeoManagerService;
use App\Services\SeoRemediationClosureService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class SeoRemediationFinalClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_execution_can_only_prepare_an_approved_undo_path_without_mutating_wordpress(): void
    {
        [$tenant, $membership] = $this->tenant('undo');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Undo', 'url' => 'https://undo.test']);
        [$execution, $remote] = $this->verifiedExecution($site, $membership->user_id);
        $gateway = new SeoRemediationWordPressGateway($remote);
        $service = new SeoRemediationClosureService($gateway, new SeoManagerService($gateway));

        $result = $service->prepareUndo($site, $execution, $membership->user_id);

        $this->assertTrue($result['requires_approval']);
        $this->assertFalse($result['mutated']);
        $this->assertSame(0, $gateway->mutationCount);
        $this->assertSame('PENDING', $result['approval']->status);
        $this->assertSame('Before title', $result['approval']->proposed_state['seo_title']);
        $this->assertSame('After title', $result['approval']->before_state['seo_title']);
        $this->assertSame('awaiting_approval', $result['suggestion']->status);
        $this->assertSame(1, Execution::query()->count());
    }

    public function test_undo_fails_closed_when_authoritative_wordpress_state_is_stale(): void
    {
        [$tenant, $membership] = $this->tenant('undo-stale');
        app(TenantContext::class)->activate($tenant, $membership);
        $site = Site::query()->create(['name' => 'Undo stale', 'url' => 'https://undo-stale.test']);
        [$execution, $remote] = $this->verifiedExecution($site, $membership->user_id);
        $remote['seo_title'] = 'Changed after execution';
        $gateway = new SeoRemediationWordPressGateway($remote);
        $service = new SeoRemediationClosureService($gateway, new SeoManagerService($gateway));
        $beforeApprovals = Approval::query()->count();

        try {
            $service->prepareUndo($site, $execution, $membership->user_id);
            $this->fail('Stale WordPress state was allowed to prepare an undo.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('UNDO_STALE_WORDPRESS_STATE', $exception->getMessage());
        }

        $this->assertSame(0, $gateway->mutationCount);
        $this->assertSame($beforeApprovals, Approval::query()->count());
    }

    public function test_remediation_proposals_and_history_are_site_scoped(): void
    {
        [$tenant, $membership] = $this->tenant('history');
        app(TenantContext::class)->activate($tenant, $membership);
        $siteA = Site::query()->create(['name' => 'A', 'url' => 'https://a.test']);
        $siteB = Site::query()->create(['name' => 'B', 'url' => 'https://b.test']);
        [$executionA, $remoteA] = $this->verifiedExecution($siteA, $membership->user_id, 41);
        $this->verifiedExecution($siteB, $membership->user_id, 42);
        $gateway = new SeoRemediationWordPressGateway($remoteA);
        $service = new SeoRemediationClosureService($gateway, new SeoManagerService($gateway));

        $proposals = $service->proposals($siteA->id);
        $history = $service->history($siteA->id);

        $this->assertCount(1, $proposals);
        $this->assertSame($siteA->id, $proposals[0]['site_id']);
        $this->assertSame('APPROVED', $proposals[0]['approval']['status']);
        $this->assertCount(1, $history);
        $this->assertSame($executionA->id, $history[0]['execution_id']);
        $this->assertTrue($history[0]['receipt']['verified']);
    }

    public function test_undo_rejects_wrong_site_before_remote_access(): void
    {
        [$tenant, $membership] = $this->tenant('wrong-site');
        app(TenantContext::class)->activate($tenant, $membership);
        $siteA = Site::query()->create(['name' => 'A', 'url' => 'https://a-wrong.test']);
        $siteB = Site::query()->create(['name' => 'B', 'url' => 'https://b-wrong.test']);
        [$execution, $remote] = $this->verifiedExecution($siteA, $membership->user_id);
        $gateway = new SeoRemediationWordPressGateway($remote);
        $service = new SeoRemediationClosureService($gateway, new SeoManagerService($gateway));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to the requested site');
        $service->prepareUndo($siteB, $execution, $membership->user_id);
    }

    private function verifiedExecution(Site $site, int $actorUserId, int $remoteId = 41): array
    {
        $before = [
            'title' => 'Page title',
            'slug' => 'page-title',
            'seo_title' => 'Before title',
            'seo_description' => 'Description',
            'seo_canonical' => 'https://example.test/page-title',
            'seo_robots' => ['follow', 'index'],
            'seo_provider' => 'yoast-seo',
            'modified_at' => '2026-08-27T00:00:00+00:00',
        ];
        $after = $before;
        $after['seo_title'] = 'After title';
        $after['modified_at'] = '2026-08-28T00:00:00+00:00';
        $after['content'] = 'Readable content.';

        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => $remoteId,
            'slug' => $after['slug'],
            'title' => $after['title'],
            'content' => $after['content'],
            'excerpt' => 'Excerpt',
            'headings' => [],
            'taxonomy' => [],
            'media' => [],
            'seo_title' => $after['seo_title'],
            'seo_description' => $after['seo_description'],
            'seo_provider' => $after['seo_provider'],
            'seo_canonical' => $after['seo_canonical'],
            'seo_robots' => $after['seo_robots'],
            'remote_modified_at' => $after['modified_at'],
        ]);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $actorUserId]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'undo-'.$remoteId,
            'severity' => 'high',
            'field' => 'seo_title',
            'recommendation' => 'Improve title',
        ]);
        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $actorUserId,
            'status' => 'ready',
            'before_state' => $before,
            'proposed_state' => ['seo_title' => 'After title'],
        ]);
        $approval = Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $actorUserId,
            'status' => 'APPROVED',
            'before_state' => $before,
            'proposed_state' => ['seo_title' => 'After title'],
            'decided_at' => now(),
        ]);
        $execution = Execution::query()->create([
            'operation_id' => fake()->uuid(),
            'request_id' => fake()->uuid(),
            'correlation_id' => fake()->uuid(),
            'site_id' => $site->id,
            'approval_id' => $approval->id,
            'actor_user_id' => $actorUserId,
            'status' => 'succeeded',
            'attempts' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        EvidenceReceipt::query()->create([
            'site_id' => $site->id,
            'execution_id' => $execution->id,
            'actor_user_id' => $actorUserId,
            'operation_id' => $execution->operation_id,
            'request_id' => $execution->request_id,
            'correlation_id' => $execution->correlation_id,
            'before_state' => $before,
            'proposed_state' => ['seo_title' => 'After title'],
            'actual_after_state' => $after,
            'verified' => true,
        ]);

        return [$execution, $after];
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
}

final class SeoRemediationWordPressGateway implements WordPressGateway
{
    public int $mutationCount = 0;

    public function __construct(private array $remote) {}

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
