<?php

namespace Tests\Feature;

use App\Connector\ConnectorResourceReader;
use App\Connector\HttpWordPressGateway;
use App\Content\ContentPlatformService;
use App\Content\Remote\ConnectorSemanticPath;
use App\Models\Connector;
use App\Models\ContentItem;
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
