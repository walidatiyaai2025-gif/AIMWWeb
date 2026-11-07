<?php

namespace Tests\Feature;

use App\Content\ContentPlatformService;
use App\Content\Remote\ContentRemoteDriver;
use App\Models\ContentItem;
use App\Models\ContentSyncState;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ContentSyncPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_sync_reads_all_pages_and_persists_more_than_one_hundred_real_posts(): void
    {
        $driver = $this->driver();
        $driver->postsPages[1] = $this->posts(1, 100);
        $driver->postsPages[2] = $this->posts(101, 1);

        $summary = app(ContentPlatformService::class)->sync(7, true);

        $this->assertSame(101, $summary['posts']['received']);
        $this->assertSame(101, $summary['posts']['changed']);
        $this->assertSame(101, ContentItem::query()->where('site_id', 7)->where('type', 'post')->count());
        $this->assertSame('Post 101', ContentItem::query()->where('site_id', 7)->where('remote_id', 101)->firstOrFail()->title);
        $this->assertSame([1, 2], $driver->readPages('posts'));
        $this->assertSame('succeeded', ContentSyncState::query()->where('site_id', 7)->where('resource', 'posts')->firstOrFail()->state);
    }

    public function test_exactly_full_page_needs_a_verified_empty_next_page_before_reporting_success(): void
    {
        $driver = $this->driver();
        $driver->postsPages[1] = $this->posts(1, 100);
        $driver->postsPages[2] = [];

        $summary = app(ContentPlatformService::class)->sync(7, true);

        $this->assertSame(100, $summary['posts']['received']);
        $this->assertSame([1, 2], $driver->readPages('posts'));
        $this->assertSame(100, ContentItem::query()->where('site_id', 7)->count());
    }

    public function test_malformed_later_page_fails_without_projecting_its_valid_siblings_or_reporting_success(): void
    {
        $driver = $this->driver();
        $driver->postsPages[1] = $this->posts(1, 100);
        $driver->postsPages[2] = [
            $this->posts(101, 1)[0],
            ['title' => 'Missing id'],
        ];

        try {
            app(ContentPlatformService::class)->sync(7, true);
            $this->fail('A malformed remote page must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('malformed resource row', $exception->getMessage());
        }

        $this->assertSame(100, ContentItem::query()->where('site_id', 7)->count());
        $this->assertDatabaseMissing('content_items', ['site_id' => 7, 'remote_id' => 101]);
        $state = ContentSyncState::query()->where('site_id', 7)->where('resource', 'posts')->firstOrFail();
        $this->assertSame('failed', $state->state);
        $this->assertNotSame(100, (int) $state->progress);
    }

    private function driver(): PagedContentReadDriver
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);

        $driver = new PagedContentReadDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        return $driver;
    }

    /** @return array<int, array<string, mixed>> */
    private function posts(int $start, int $count): array
    {
        return array_map(static fn (int $id): array => [
            'id' => $id,
            'title' => 'Post '.$id,
            'slug' => 'post-'.$id,
            'status' => 'publish',
            'content' => 'Real WordPress content',
            'modified_gmt' => '2026-11-07T00:00:00Z',
        ], range($start, $start + $count - 1));
    }
}

final class PagedContentReadDriver implements ContentRemoteDriver
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $postsPages = [];

    /** @var array<int, array{resource:string,page:int}> */
    public array $requests = [];

    public function list(int $siteId, string $resource, array $query = []): array
    {
        $page = (int) ($query['page'] ?? 1);
        $this->requests[] = ['resource' => $resource, 'page' => $page];

        return $resource === 'posts' ? ($this->postsPages[$page] ?? []) : [];
    }

    /** @return array<int, int> */
    public function readPages(string $resource): array
    {
        return array_values(array_map(
            static fn (array $request): int => $request['page'],
            array_filter($this->requests, static fn (array $request): bool => $request['resource'] === $resource),
        ));
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        throw new RuntimeException('Not used by read-only sync test.');
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        throw new RuntimeException('Not used by read-only sync test.');
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        throw new RuntimeException('Not used by read-only sync test.');
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        throw new RuntimeException('Not used by read-only sync test.');
    }
}
