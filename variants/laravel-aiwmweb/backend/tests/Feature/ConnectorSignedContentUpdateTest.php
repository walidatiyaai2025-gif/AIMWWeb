<?php

namespace Tests\Feature;

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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ConnectorSignedContentUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_connector_update_posts_only_supported_top_level_mutation_and_verifies_get(): void
    {
        $site = $this->site();
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = [$request->method(), $request->url(), $request->data()];
            if ($request->method() === 'POST') {
                $this->assertStringEndsWith('/wp-json/aimw/v1/execute', $request->url());
                $this->assertTrue($request->hasHeader('X-AIMW-Scope', 'content.update'));
                $this->assertTrue($request->hasHeader('X-AIMW-Signature'));
                $this->assertTrue($request->hasHeader('X-AIMW-Operation-ID'));
                $this->assertSame([
                    'resource_type' => 'post',
                    'remote_id' => 31,
                    'changes' => ['title' => 'Updated article'],
                ], $request->data());

                return Http::response(['status' => 'succeeded', 'after' => ['id' => 31, 'type' => 'post']], 200);
            }

            $this->assertSame('GET', $request->method());
            $this->assertStringEndsWith('/wp-json/aimw/v1/content/resources/posts/31', $request->url());
            $this->assertTrue($request->hasHeader('X-AIMW-Scope', 'content.read'));
            $this->assertTrue($request->hasHeader('X-AIMW-Signature'));

            return Http::response(['id' => 31, 'type' => 'post', 'title' => 'Updated article', 'slug' => 'article', 'content' => 'Before'], 200);
        });

        $saved = app(ConnectorSemanticPath::class)->mutate($site->id, 'posts', 31, 'update', ['title' => 'Updated article']);
        $this->assertSame('Updated article', $saved['title']);
        $this->assertSame(['POST', 'GET'], array_column($calls, 0));
    }

    public function test_only_owner_enabled_read_and_write_scopes_allow_signed_edits(): void
    {
        $site = $this->site();
        Http::fake();
        foreach (['content.read', 'content.update'] as $missing) {
            Connector::query()->where('site_id', $site->id)->update([
                'enabled_scopes' => array_values(array_diff(['content.read', 'content.update'], [$missing])),
            ]);
            try {
                app(ConnectorSemanticPath::class)->mutate($site->id, 'pages', 21, 'update', ['title' => 'Only with consent']);
                $this->fail('Signed update must be denied before writing when a required scope is missing.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($missing, $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_unsupported_or_ambiguous_connector_writes_reject_without_remote_requests(): void
    {
        $site = $this->site();
        Http::fake();
        $cases = [
            ['posts', null, 'create', ['title' => 'New']],
            ['posts', 31, 'delete', []],
            ['pages', 31, 'publish', ['status' => 'publish']],
            ['comments', 31, 'update', ['title' => 'No']],
            ['pages', 31, 'update', ['status' => 'publish']],
            ['posts', 31, 'update', ['title' => 'Text', 'status' => 'draft']],
            ['posts', 31, 'update', ['title' => 42]],
            ['posts', 0, 'update', ['title' => 'Invalid ID']],
            ['pages', 31, 'update', []],
        ];

        foreach ($cases as [$resource, $id, $action, $payload]) {
            try {
                app(ConnectorSemanticPath::class)->mutate($site->id, $resource, $id, $action, $payload);
                $this->fail('Unsupported Connector mutation must fail closed before any network request.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    public function test_http_200_failed_or_malformed_receipts_cannot_claim_saved(): void
    {
        $site = $this->site();
        $sequence = Http::fakeSequence();
        $sequence->push(['status' => 'failed', 'after' => ['id' => 31, 'type' => 'post']], 200);
        $sequence->push(['status' => 'succeeded', 'after' => ['id' => 999, 'type' => 'post']], 200);
        foreach ([1, 2] as $_case) {
            try {
                app(ConnectorSemanticPath::class)->mutate($site->id, 'posts', 31, 'update', ['title' => 'Changed']);
                $this->fail('Unverified signed write receipt must not be treated as success.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unverified completion receipt', $exception->getMessage());
            }
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_stale_signed_readback_does_not_claim_success_or_update_local_content(): void
    {
        $site = $this->site();
        $item = ContentItem::query()->create([
            'site_id' => $site->id, 'remote_id' => 31, 'type' => 'post', 'title' => 'Before',
        ]);
        $readCount = 0;
        Http::fake(function ($request) use (&$readCount) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'succeeded', 'after' => ['id' => 31, 'type' => 'post']], 200);
            }
            $readCount++;

            return Http::response([
                'id' => 31, 'type' => 'post', 'title' => 'Before',
                'slug' => 'before', 'content' => 'Body',
            ], 200);
        });

        try {
            app(ContentPlatformService::class)->mutateContent($site->id, 'post', 31, 'update', ['title' => 'After']);
            $this->fail('A stale WordPress reread must block canonical save success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('readback differs', $exception->getMessage());
        }
        $this->assertSame(2, $readCount);
        $this->assertSame('Before', $item->fresh()->title);
    }

    public function test_real_verified_wordpress_result_updates_local_canonical_content(): void
    {
        $site = $this->site();
        $item = ContentItem::query()->create([
            'site_id' => $site->id, 'remote_id' => 31, 'type' => 'post', 'title' => 'Before',
        ]);
        $readCount = 0;
        Http::fake(function ($request) use (&$readCount) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'succeeded', 'after' => ['id' => 31, 'type' => 'post']], 200);
            }
            $readCount++;

            return Http::response([
                'id' => 31, 'type' => 'post',
                'title' => $readCount === 1 ? 'Before' : 'After',
                'slug' => 'same', 'content' => 'Body', 'status' => 'draft',
            ], 200);
        });

        $saved = app(ContentPlatformService::class)->mutateContent($site->id, 'post', 31, 'update', ['title' => 'After']);
        $this->assertSame('After', $saved['title']);
        $this->assertSame('After', $item->fresh()->title);
        $this->assertSame(2, $readCount);
        Http::assertSentCount(3);
    }

    public function test_guessed_foreign_tenant_site_id_never_reaches_signed_wordpress(): void
    {
        $site = $this->site();
        $foreign = Tenant::query()->create(['slug' => 'beta', 'name' => 'Beta']);
        app(TenantContext::class)->activate($foreign);
        Http::fake();
        try {
            app(ConnectorSemanticPath::class)->mutate($site->id, 'posts', 31, 'update', ['title' => 'Foreign']);
            $this->fail('Foreign site cannot be signed from another tenant context.');
        } catch (ModelNotFoundException) {
            Http::assertNothingSent();
        }
    }

    private function site(): Site
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'alpha'], ['name' => 'Alpha']);
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create(['name' => 'Test WordPress', 'url' => 'https://wp.test', 'status' => 'active']);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => '22222222-2222-4222-8222-222222222222',
            'encrypted_secret' => 'test-only-secret',
            'capabilities' => ['health', 'content.read', 'content.update'],
            'enabled_scopes' => ['content.read', 'content.update'],
        ]);

        return $site;
    }
}
