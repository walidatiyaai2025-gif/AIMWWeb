<?php

namespace Tests\Feature;

use App\Content\ContentConflictException;
use App\Content\ContentPlatformService;
use App\Content\Remote\ContentRemoteDriver;
use App\Jobs\BulkContentMutationJob;
use App\Models\Comment;
use App\Models\ContentConflict;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentSyncState;
use App\Models\ContentTransfer;
use App\Models\MediaItem;
use App\Models\TaxonomyTerm;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContentPlatformIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_content_family_is_tenant_scoped_even_for_guessed_ids(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        $context = app(TenantContext::class);
        $context->activate($tenantB);
        $content = ContentItem::query()->create(['site_id' => 7, 'remote_id' => 101, 'type' => 'post', 'title' => 'B']);
        $revision = ContentRevision::query()->create(['site_id' => 7, 'content_item_id' => $content->id, 'remote_id' => 5, 'snapshot' => ['title' => 'B'], 'content_hash' => str_repeat('b', 64)]);
        $media = MediaItem::query()->create(['site_id' => 7, 'remote_id' => 201, 'title' => 'B media']);
        $comment = Comment::query()->create(['site_id' => 7, 'remote_id' => 301, 'body' => 'B comment']);
        $term = TaxonomyTerm::query()->create(['site_id' => 7, 'remote_id' => 401, 'taxonomy' => 'category', 'name' => 'B', 'slug' => 'b']);
        $sync = ContentSyncState::query()->create(['site_id' => 7, 'resource' => 'posts']);
        $conflict = ContentConflict::query()->create(['site_id' => 7, 'entity_type' => 'ContentItem', 'entity_id' => $content->id, 'remote_id' => 101]);
        $transfer = ContentTransfer::query()->create(['site_id' => 7, 'kind' => 'export']);

        $context->activate($tenantA);
        foreach ([[ContentItem::class, $content->id], [ContentRevision::class, $revision->id], [MediaItem::class, $media->id], [Comment::class, $comment->id], [TaxonomyTerm::class, $term->id], [ContentSyncState::class, $sync->id], [ContentConflict::class, $conflict->id], [ContentTransfer::class, $transfer->id]] as [$class,$id]) {
            $this->assertNull($class::query()->find($id), "{$class} leaked across tenant scope");
        }
    }

    public function test_site_scope_blocks_guessed_ids_and_bulk_selection_before_dispatch(): void
    {
        Bus::fake();
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        app(TenantContext::class)->activate($tenant);
        $siteOne = ContentItem::query()->create(['site_id' => 1, 'remote_id' => 10, 'type' => 'post']);
        $siteTwo = ContentItem::query()->create(['site_id' => 2, 'remote_id' => 20, 'type' => 'post']);
        $this->assertNull(ContentItem::query()->where('site_id', 1)->find($siteTwo->id));

        $job = new BulkContentMutationJob($tenant->id, 1, [$siteOne->id, $siteTwo->id], 'publish');
        $this->expectException(HttpException::class);
        $job->handle(app(ContentPlatformService::class));
    }

    public function test_remote_change_creates_conflict_and_never_calls_mutation(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        app(TenantContext::class)->activate($tenant);
        $local = ContentItem::query()->create(['site_id' => 1, 'remote_id' => 10, 'type' => 'post', 'title' => 'old', 'remote_hash' => str_repeat('a', 64), 'remote_modified_at' => '2026-08-01 00:00:00', 'remote_version' => 'v1']);
        $driver = new FakeContentDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);
        $service = $this->app->make(ContentPlatformService::class);
        try {
            $service->mutateContent(1, 'post', 10, 'update', ['title' => 'mine'], ['hash' => $local->remote_hash, 'modified_at' => $local->remote_modified_at->toIso8601String(), 'version' => 'v1']);
            $this->fail('Expected conflict.');
        } catch (ContentConflictException $e) {
            $this->assertGreaterThan(0, $e->conflictId);
        }
        $this->assertSame(1, ContentConflict::query()->where('site_id', 1)->where('status', 'open')->count());
        $this->assertSame(0, $driver->mutations);
        $this->assertSame('old', ContentItem::query()->findOrFail($local->id)->title);
    }

    public function test_initial_sync_persists_normalized_content_media_comments_taxonomy_and_progress(): void
    {
        $tenant = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        app(TenantContext::class)->activate($tenant);
        $this->app->instance(ContentRemoteDriver::class, new FakeContentDriver);
        $summary = $this->app->make(ContentPlatformService::class)->sync(9, true);
        $this->assertSame('Hello', ContentItem::query()->where('site_id', 9)->where('type', 'post')->firstOrFail()->title);
        $this->assertSame('Page', ContentItem::query()->where('site_id', 9)->where('type', 'page')->firstOrFail()->title);
        $this->assertSame('hero.jpg', MediaItem::query()->where('site_id', 9)->firstOrFail()->title);
        $this->assertSame('Approved comment', Comment::query()->where('site_id', 9)->firstOrFail()->body);
        $this->assertSame('News', TaxonomyTerm::query()->where('site_id', 9)->where('taxonomy', 'category')->firstOrFail()->name);
        $this->assertSame('Tag', TaxonomyTerm::query()->where('site_id', 9)->where('taxonomy', 'post_tag')->firstOrFail()->name);
        $this->assertSame(6, ContentSyncState::query()->where('site_id', 9)->where('state', 'succeeded')->where('progress', 100)->count());
        $this->assertSame(1, $summary['posts']['received']);
    }
}

final class FakeContentDriver implements ContentRemoteDriver
{
    public int $mutations = 0;

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return match ($resource) {
            'posts' => [['id' => 1, 'title' => ['rendered' => 'Hello'], 'slug' => 'hello', 'status' => 'publish', 'content' => ['rendered' => 'Body'], 'excerpt' => ['rendered' => 'Excerpt'], 'modified_gmt' => '2026-08-27T12:00:00Z']],
            'pages' => [['id' => 2, 'title' => ['rendered' => 'Page'], 'slug' => 'page', 'status' => 'draft', 'content' => ['rendered' => 'Page body'], 'modified_gmt' => '2026-08-27T12:00:00Z']],
            'media' => [['id' => 3, 'title' => ['rendered' => 'hero.jpg'], 'slug' => 'hero', 'media_type' => 'image', 'mime_type' => 'image/jpeg', 'source_url' => 'https://example.test/hero.jpg', 'modified_gmt' => '2026-08-27T12:00:00Z']],
            'categories' => [['id' => 4, 'name' => 'News', 'slug' => 'news', 'count' => 1]],
            'tags' => [['id' => 5, 'name' => 'Tag', 'slug' => 'tag', 'count' => 1]],
            'comments' => [['id' => 6, 'post' => 1, 'author_name' => 'A', 'content' => ['rendered' => 'Approved comment'], 'status' => 'approved', 'date_gmt' => '2026-08-27T12:00:00Z']],
            default => [],
        };
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        return ['id' => $remoteId, 'title' => ['rendered' => 'remote changed'], 'modified_gmt' => '2026-08-27T13:00:00Z', 'version' => 'v2'];
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;

        return ['id' => $remoteId ?? 99] + $payload;
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        return ['id' => 100, 'source_url' => 'https://example.test/'.$name];
    }

    public function semantic(int $siteId,string $operation,array $payload = []): array
    {
        return [];
    }
}
