<?php

namespace Tests\Feature\Sync;

use App\Content\Remote\ContentRemoteDriver;
use App\Jobs\ProcessSyncRunJob;
use App\Models\ContentConflict;
use App\Models\ContentItem;
use App\Models\SyncBatch;
use App\Models\SyncEvent;
use App\Models\SyncItem;
use App\Models\SyncResourceVersion;
use App\Models\SyncRun;
use App\Models\SyncSiteLease;
use App\Models\SyncTombstone;
use App\Models\SyncWebhookEvent;
use App\Models\Tenant;
use App\Sync\Contracts\SyncSiteGuard;
use App\Sync\Contracts\SyncWebhookVerifier;
use App\Sync\SyncRuntimeService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SyncReconciliationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private FakeSyncDriver $driver;

    private FakeSyncSiteGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->driver = new FakeSyncDriver;
        $this->guard = new FakeSyncSiteGuard(app(TenantContext::class));
        $this->app->instance(ContentRemoteDriver::class, $this->driver);
        $this->app->instance(SyncSiteGuard::class, $this->guard);
    }

    public function test_initial_and_incremental_sync_persist_history_baselines_and_real_counts(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $this->driver->set('posts', 1, [$this->post(10, 'Initial', '2026-08-27T10:00:00Z')]);

        $run = $this->syncRunRecord(1, true, ['posts']);
        $this->assertSame('completed', $run->state);
        $this->assertSame(1, $run->discovered_count);
        $this->assertSame(1, $run->created_count);
        $this->assertSame('Initial', ContentItem::query()->where('site_id', 1)->firstOrFail()->title);
        $this->assertSame(1, SyncResourceVersion::query()->where('site_id', 1)->count());
        $this->assertTrue(SyncEvent::query()->where('event_type', 'SyncStarted')->exists());
        $this->assertTrue(SyncEvent::query()->where('event_type', 'SyncCompleted')->exists());

        $this->driver->set('posts', 1, [$this->post(10, 'Remote changed', '2026-08-27T11:00:00Z')]);
        $incremental = $this->syncRunRecord(1, false, ['posts']);
        $this->assertSame('completed', $incremental->state);
        $this->assertSame(1, $incremental->updated_count);
        $this->assertSame('Remote changed', ContentItem::query()->where('site_id', 1)->firstOrFail()->title);
    }

    public function test_local_and_remote_change_creates_conflict_without_silent_overwrite_then_keep_remote_rereads_authority(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $this->driver->set('posts', 1, [$this->post(10, 'Base', '2026-08-27T10:00:00Z')]);
        $this->syncRunRecord(1, true, ['posts']);

        $local = ContentItem::query()->where('site_id', 1)->firstOrFail();
        $local->forceFill(['title' => 'Local edit'])->save();
        $this->driver->set('posts', 1, [$this->post(10, 'Remote edit', '2026-08-27T11:00:00Z')]);

        $run = $this->syncRunRecord(1, false, ['posts']);
        $this->assertSame(1, $run->conflicted_count);
        $this->assertSame('Local edit', $local->fresh()->title);
        $conflict = ContentConflict::query()->where('site_id', 1)->where('status', 'open')->firstOrFail();
        $this->assertSame('posts', $conflict->resource);
        $this->assertNotNull($conflict->local_hash);
        $this->assertNotNull($conflict->remote_hash);

        $resolved = app(SyncRuntimeService::class)->resolveConflict($conflict, 'KEEP_REMOTE', [], null);
        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('KEEP_REMOTE', $resolved->resolution);
        $this->assertSame('Remote edit', $local->fresh()->title);
        $this->assertSame(1, $this->driver->reads);
    }

    public function test_keep_local_is_explicit_remote_write_followed_by_authoritative_reread(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $this->driver->set('posts', 1, [$this->post(10, 'Base', '2026-08-27T10:00:00Z')]);
        $this->syncRunRecord(1, true, ['posts']);
        $local = ContentItem::query()->where('site_id', 1)->firstOrFail();
        $local->forceFill(['title' => 'Keep me'])->save();
        $this->driver->set('posts', 1, [$this->post(10, 'Remote edit', '2026-08-27T11:00:00Z')]);
        $this->syncRunRecord(1, false, ['posts']);

        $conflict = ContentConflict::query()->where('status', 'open')->firstOrFail();
        app(SyncRuntimeService::class)->resolveConflict($conflict, 'KEEP_LOCAL', [], null);
        $this->assertSame(1, $this->driver->mutations);
        $this->assertGreaterThanOrEqual(1, $this->driver->reads);
        $this->assertSame('Keep me', ContentItem::query()->findOrFail($local->id)->title);
    }

    public function test_retry_reconciliation_resolution_queues_a_real_resource_reconciliation(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $conflict = ContentConflict::query()->create([
            'site_id' => 1,
            'resource' => 'posts',
            'entity_type' => 'ContentItem',
            'remote_id' => 10,
            'status' => 'open',
        ]);

        $resolved = app(SyncRuntimeService::class)->resolveConflict($conflict, 'RETRY_RECONCILIATION', [], null);

        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('RETRY_RECONCILIATION', $resolved->resolution);
        $run = SyncRun::query()->where('trigger', 'conflict-retry')->firstOrFail();
        $this->assertSame(['posts'], $run->resources);
        Queue::assertPushed(ProcessSyncRunJob::class, fn (ProcessSyncRunJob $job) => $job->runId === $run->id);
    }

    public function test_remote_missing_is_not_deleted_until_two_completed_full_observations(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $this->driver->set('posts', 1, [$this->post(10, 'Base', '2026-08-27T10:00:00Z')]);
        $this->syncRunRecord(1, true, ['posts']);
        $local = ContentItem::query()->where('site_id', 1)->firstOrFail();

        $this->driver->set('posts', 1, []);
        $firstMissing = $this->syncRunRecord(1, true, ['posts']);
        $this->assertSame(0, $firstMissing->deleted_count);
        $this->assertFalse((bool) $local->fresh()->stale);
        $this->assertSame(1, SyncTombstone::query()->firstOrFail()->missing_observations);

        $secondMissing = $this->syncRunRecord(1, true, ['posts']);
        $this->assertSame(1, $secondMissing->deleted_count);
        $this->assertTrue((bool) $local->fresh()->stale);
        $this->assertNotNull(SyncTombstone::query()->firstOrFail()->confirmed_deleted_at);
    }

    public function test_large_sync_is_batched_and_resumable_without_unbounded_loading(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $pageOne = [];
        for ($id = 1; $id <= 100; $id++) {
            $pageOne[] = $this->post($id, "Post {$id}", '2026-08-27T10:00:00Z');
        }
        $this->driver->set('posts', 1, $pageOne);
        $this->driver->set('posts', 2, [$this->post(101, 'Post 101', '2026-08-27T10:01:00Z')]);

        $runtime = app(SyncRuntimeService::class);
        $run = $runtime->start($tenant->id, 1, true, ['posts']);
        $runtime->processRun($tenant->id, $run->id);
        $first = SyncBatch::query()->where('sync_run_id', $run->id)->where('page', 1)->firstOrFail();
        $runtime->processBatch($tenant->id, $first->id);
        $second = SyncBatch::query()->where('sync_run_id', $run->id)->where('page', 2)->firstOrFail();
        $runtime->processBatch($tenant->id, $second->id);

        $run->refresh();
        $this->assertSame('completed', $run->state);
        $this->assertSame(101, $run->created_count);
        $this->assertSame(2, SyncBatch::query()->where('sync_run_id', $run->id)->count());
        $this->assertSame([100, 100], $this->driver->perPageValues);
    }

    public function test_partial_item_failure_can_be_retried_without_repeating_successful_items(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $this->driver->set('posts', 1, [
            $this->post(1, 'Good', '2026-08-27T10:00:00Z'),
            ['id' => 0, 'title' => ['rendered' => 'Bad']],
        ]);

        $run = $this->syncRunRecord(1, true, ['posts']);
        $this->assertSame('partial', $run->state);
        $this->assertSame(1, $run->created_count);
        $this->assertSame(1, $run->failed_count);
        $failed = SyncItem::query()->where('state', 'failed')->firstOrFail();
        $failed->forceFill(['remote_payload' => $this->post(2, 'Recovered', '2026-08-27T10:02:00Z')])->save();

        app(SyncRuntimeService::class)->processRetryItem($failed->id);
        $this->assertSame('completed', $failed->fresh()->state);
        $this->assertSame(2, ContentItem::query()->where('site_id', 1)->count());
        $this->assertSame(0, $run->fresh()->failed_count);
        $this->assertSame('completed', $run->fresh()->state);
    }

    public function test_terminal_batch_failure_releases_site_lease(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $this->activate($tenant);
        $runtime = app(SyncRuntimeService::class);
        $run = $runtime->start($tenant->id, 1, true, ['posts']);
        $runtime->processRun($tenant->id, $run->id);
        $batch = SyncBatch::query()->where('sync_run_id', $run->id)->firstOrFail();
        $this->assertTrue(SyncSiteLease::query()->where('site_id', 1)->exists());

        $runtime->recordBatchFailure($batch->id, new RuntimeException('remote down'), true);
        $this->assertFalse(SyncSiteLease::query()->where('site_id', 1)->exists());
        $this->assertSame('failed', $run->fresh()->state);
    }

    public function test_tenant_scope_blocks_foreign_runs_and_foreign_site_start(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $this->guard->allow(1, $tenantA->id);
        $this->guard->allow(2, $tenantB->id);

        $this->activate($tenantB);
        $runB = app(SyncRuntimeService::class)->start($tenantB->id, 2, true, ['posts']);
        $this->activate($tenantA);
        $this->assertNull(SyncRun::query()->find($runB->id));

        $this->expectException(HttpException::class);
        app(SyncRuntimeService::class)->start($tenantA->id, 2, true, ['posts']);
    }

    public function test_webhook_is_verified_idempotent_and_invalid_events_never_create_sync_state(): void
    {
        $tenant = $this->tenant('a');
        $this->guard->allow(1, $tenant->id);
        $event = [
            'tenant_id' => $tenant->id,
            'site_id' => 1,
            'connector_id' => 77,
            'event_id' => 'evt-1',
            'event_type' => 'post.updated',
            'occurred_at' => Carbon::parse('2026-08-27T10:00:00Z'),
            'resource' => 'posts',
            'remote_id' => 10,
            'action' => 'updated',
            'payload' => [],
        ];
        $this->app->instance(SyncWebhookVerifier::class, new FakeWebhookVerifier($event));

        $this->postJson('/api/v1/sync/webhooks/connector', ['event_id' => 'ignored'])
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted');
        $this->postJson('/api/v1/sync/webhooks/connector', ['event_id' => 'ignored'])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, SyncWebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(1, SyncRun::withoutGlobalScopes()->where('trigger', 'webhook')->count());

        $event['event_id'] = 'evt-2';
        $this->app->instance(SyncWebhookVerifier::class, new FakeWebhookVerifier($event));
        $this->postJson('/api/v1/sync/webhooks/connector')
            ->assertAccepted()
            ->assertJsonPath('status', 'deferred');
        $this->assertSame('deferred', SyncWebhookEvent::withoutGlobalScopes()->where('event_id', 'evt-2')->value('state'));
        $this->assertSame(1, SyncRun::withoutGlobalScopes()->where('trigger', 'webhook')->count());

        $this->app->instance(SyncWebhookVerifier::class, new RejectingWebhookVerifier);
        $this->postJson('/api/v1/sync/webhooks/connector')->assertUnauthorized();
        $this->assertSame(2, SyncWebhookEvent::withoutGlobalScopes()->count());
    }

    private function syncRunRecord(int $siteId, bool $full, array $resources): SyncRun
    {
        $tenantId = app(TenantContext::class)->id();
        $runtime = app(SyncRuntimeService::class);
        $run = $runtime->start($tenantId, $siteId, $full, $resources);
        $runtime->processRun($tenantId, $run->id);
        foreach (SyncBatch::query()->where('sync_run_id', $run->id)->orderBy('id')->get() as $batch) {
            $runtime->processBatch($tenantId, $batch->id);
        }

        return $run->fresh();
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create(['name' => strtoupper($slug), 'slug' => $slug]);
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }

    private function post(int $id, string $title, string $modified): array
    {
        return [
            'id' => $id,
            'title' => ['rendered' => $title],
            'slug' => 'post-'.$id,
            'status' => 'publish',
            'content' => ['rendered' => 'Body '.$id],
            'excerpt' => ['rendered' => 'Excerpt '.$id],
            'modified_gmt' => $modified,
            'version' => $modified,
        ];
    }
}

final class FakeSyncSiteGuard implements SyncSiteGuard
{
    public array $sites = [];

    public function __construct(private readonly TenantContext $context) {}

    public function allow(int $siteId, int $tenantId): void
    {
        $this->sites[$siteId] = $tenantId;
    }

    public function assertAccessible(int $siteId): void
    {
        if (($this->sites[$siteId] ?? null) !== $this->context->id()) {
            abort(404, 'Site not found.');
        }
    }
}

final class FakeSyncDriver implements ContentRemoteDriver
{
    public array $pages = [];

    public array $perPageValues = [];

    public int $mutations = 0;

    public int $reads = 0;

    public function set(string $resource, int $page, array $rows): void
    {
        $this->pages[$resource][$page] = $rows;
    }

    public function list(int $siteId, string $resource, array $query = []): array
    {
        $this->perPageValues[] = (int) ($query['per_page'] ?? 0);

        return $this->pages[$resource][(int) ($query['page'] ?? 1)] ?? [];
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        $this->reads++;
        foreach ($this->pages[$resource] ?? [] as $rows) {
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $remoteId) {
                    return $row;
                }
            }
        }

        throw new RuntimeException('Remote object not found.');
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;
        foreach ($this->pages[$resource] ?? [] as $page => $rows) {
            foreach ($rows as $index => $row) {
                if ((int) ($row['id'] ?? 0) === $remoteId) {
                    $row['title'] = ['rendered' => (string) ($payload['title'] ?? data_get($row, 'title.rendered', ''))];
                    $row['modified_gmt'] = '2026-08-27T12:00:00Z';
                    $row['version'] = $row['modified_gmt'];
                    $this->pages[$resource][$page][$index] = $row;

                    return $row;
                }
            }
        }

        throw new RuntimeException('Remote object not found.');
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        return [];
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        return [];
    }
}

final class FakeWebhookVerifier implements SyncWebhookVerifier
{
    public function __construct(private readonly array $event) {}

    public function verify(Request $request): array
    {
        return $this->event;
    }
}

final class RejectingWebhookVerifier implements SyncWebhookVerifier
{
    public function verify(Request $request): array
    {
        throw new RuntimeException('bad signature');
    }
}
