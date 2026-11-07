<?php

namespace Tests\Feature;

use App\Connector\ConnectorResourceReader;
use App\Connector\HttpWordPressGateway;
use App\Content\ContentPlatformService;
use App\Content\Remote\ConnectorSemanticPath;
use App\Models\Connector;
use App\Models\ContentItem;
use App\Models\ContentSyncState;
use App\Models\Site;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ConnectorCanonicalContentReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_connector_resource_uses_signed_get_instead_of_execute(): void
    {
        $site = $this->connectedSite();
        Http::fake([
            'https://wp.test/wp-json/aimw/v1/content/resources/posts*' => Http::response([
                'items' => [['id' => 37, 'title' => 'Real WordPress article', 'status' => 'publish']],
            ]),
        ]);

        $reader = app(ConnectorResourceReader::class);
        $rows = $reader->listResource($site, 'posts', ['page' => 1, 'per_page' => 100]);

        $this->assertSame(37, $rows[0]['id']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/aimw/v1/content/resources/posts?page=1&per_page=100')
            && $request->hasHeader('X-AIMW-Scope', 'content.read')
            && $request->hasHeader('X-AIMW-Signature')
            && ! str_contains($request->url(), '/execute'));
    }

    public function test_content_platform_sync_projects_real_connector_only_results_into_content_items(): void
    {
        $site = $this->connectedSite();
        $reader = new CanonicalContentReaderStub;
        $reader->payloads['posts'] = [[
            'id' => 37,
            'title' => 'NOB article from signed WordPress',
            'slug' => 'nob-article',
            'content' => 'Actual synced article text',
            'status' => 'publish',
            'modified_gmt' => '2026-11-05T00:00:00Z',
        ]];
        $reader->payloads['pages'] = [[
            'id' => 9,
            'title' => 'NOB WordPress page',
            'slug' => 'nob-page',
            'content' => 'Actual page text',
            'status' => 'publish',
            'modified_gmt' => '2026-11-05T00:00:00Z',
        ]];
        $this->app->instance(ConnectorResourceReader::class, $reader);

        $summary = app(ContentPlatformService::class)->sync($site->id, true);

        $this->assertSame(1, $summary['posts']['received']);
        $this->assertSame(1, $summary['pages']['received']);
        $this->assertSame(1, ContentItem::query()->where('site_id', $site->id)->where('type', 'post')->count());
        $this->assertSame('NOB article from signed WordPress', ContentItem::query()->where('type', 'post')->firstOrFail()->title);
        $this->assertSame('NOB WordPress page', ContentItem::query()->where('type', 'page')->firstOrFail()->title);
        $this->assertSame(['posts', 'pages', 'media', 'categories', 'tags', 'comments'], $reader->resources);
    }

    public function test_missing_connector_scope_and_invalid_response_fail_closed(): void
    {
        $site = $this->connectedSite();
        Connector::query()->where('site_id', $site->id)->update(['enabled_scopes' => ['health']]);
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required connector scope is disabled');
        app(HttpWordPressGateway::class)->listResource($site, 'posts');
        Http::assertNothingSent();
    }

    public function test_invalid_signed_content_payload_does_not_fabricate_empty_results(): void
    {
        $site = $this->connectedSite();
        Http::fake(['https://wp.test/*' => Http::response(['ok' => true])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid items contract');
        app(HttpWordPressGateway::class)->listResource($site, 'posts');
    }

    public function test_legitimate_empty_signed_resource_pages_remain_valid(): void
    {
        $site = $this->connectedSite();
        Http::fake([
            'https://wp.test/wp-json/aimw/v1/content/resources/posts*' => Http::response(['items' => []], 200),
        ]);

        $this->assertSame([], app(HttpWordPressGateway::class)->listResource($site, 'posts'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->hasHeader('X-AIMW-Scope', 'content.read')
            && $request->hasHeader('X-AIMW-Signature'));
    }

    public function test_error_marked_http_200_signed_pages_are_never_empty_sync_evidence(): void
    {
        $site = $this->connectedSite();

        $cases = [
            ['items' => [], 'success' => false],
            ['items' => [], 'error' => 'upstream_unavailable'],
            ['items' => [], 'status' => 'failed'],
            ['items' => [['id' => 5]], 'error' => ['code' => 'partial_failure']],
        ];
        $responses = Http::fakeSequence();
        foreach ($cases as $case) {
            $responses->push($case, 200);
        }

        foreach ($cases as $case) {
            try {
                app(HttpWordPressGateway::class)->listResource($site, 'posts');
                $this->fail('Error-marked HTTP 200 must not be treated as a genuine WordPress resource page.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('error-marked response', $exception->getMessage());
            }
        }
        Http::assertSentCount(count($cases));
    }

    public function test_signed_list_validates_all_remote_identities_and_page_bounds_before_projection(): void
    {
        $site = $this->connectedSite();
        $malformed = [
            ['items' => [['title' => 'Missing ID']]],
            ['items' => [['id' => 0, 'title' => 'Invalid']]],
            ['items' => [['id' => 'not-an-id', 'title' => 'Invalid']]],
            ['items' => [null]],
            ['items' => ['scalar']],
            ['items' => [['id' => 3], ['id' => []]]],
        ];

        // A single ordered fake sequence guarantees every adversarial case
        // is actually exercised rather than reusing the first registered stub.
        $responses = Http::fakeSequence();
        foreach ($malformed as $payload) {
            $responses->push($payload, 200);
        }
        $responses->push(['items' => array_fill(0, 101, ['id' => 1])], 200);

        foreach ($malformed as $payload) {
            try {
                app(HttpWordPressGateway::class)->listResource($site, 'posts');
                $this->fail('Signed WordPress resource listing must validate every row before returning.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('malformed resource record', $exception->getMessage());
            }
        }

        try {
            app(HttpWordPressGateway::class)->listResource($site, 'posts', ['per_page' => 100]);
            $this->fail('A page exceeding the signed request limit must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid items contract', $exception->getMessage());
        }
        Http::assertSentCount(count($malformed) + 1);
    }

    public function test_signed_list_error_preserves_existing_canonical_content_and_fails_sync_closed(): void
    {
        $site = $this->connectedSite();
        ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => 51,
            'type' => 'post',
            'title' => 'Previously verified article',
        ]);

        // This was previously accepted as a successful zero-item remote page.
        Http::fake(['https://wp.test/*' => Http::response(['items' => [], 'success' => false], 200)]);
        try {
            app(ContentPlatformService::class)->sync($site->id, true);
            $this->fail('An upstream error must abort canonical sync rather than look like zero posts.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('error-marked response', $exception->getMessage());
        }

        $this->assertSame('Previously verified article',
            ContentItem::query()->where('site_id', $site->id)->where('remote_id', 51)->firstOrFail()->title);
        $state = ContentSyncState::query()->where('site_id', $site->id)->where('resource', 'posts')->firstOrFail();
        $this->assertSame('failed', $state->state);
        $this->assertNotSame(100, (int) $state->progress);
        Http::assertSentCount(1);
    }

    public function test_signed_single_item_read_rejects_error_envelope_even_with_matching_id(): void
    {
        $site = $this->connectedSite();
        Http::fake(['https://wp.test/*' => Http::response(['id' => 37, 'success' => false], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error-marked response');
        app(HttpWordPressGateway::class)->getResource($site, 'posts', 37);
    }

    public function test_connector_only_resource_read_rejects_foreign_tenant_site(): void
    {
        $site = $this->connectedSite();
        $tenant = Tenant::query()->create(['slug' => 'foreign', 'name' => 'Foreign']);
        app(TenantContext::class)->activate($tenant);
        $this->expectException(ModelNotFoundException::class);
        app(ConnectorSemanticPath::class)->list($site->id, 'posts', ['page' => 1]);
    }

    private function connectedSite(): Site
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'wordpress'], ['name' => 'WordPress']);
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create(['name' => 'NOB', 'url' => 'https://wp.test', 'status' => 'active']);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => '11111111-1111-4111-8111-111111111111',
            'encrypted_secret' => 'test-only-connector-secret',
            'capabilities' => ['health', 'content.read'],
            'enabled_scopes' => ['health', 'content.read'],
        ]);

        return $site;
    }
}

final class CanonicalContentReaderStub implements ConnectorResourceReader
{
    public array $payloads = [];

    public array $resources = [];

    public function listResource(Site $site, string $resource, array $query = []): array
    {
        $this->resources[] = $resource;

        return $this->payloads[$resource] ?? [];
    }

    public function getResource(Site $site, string $resource, int $remoteId): array
    {
        foreach ($this->payloads[$resource] ?? [] as $item) {
            if (($item['id'] ?? null) === $remoteId) {
                return $item;
            }
        }

        throw new RuntimeException('Resource not found.');
    }
}
