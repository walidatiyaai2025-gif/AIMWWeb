<?php

namespace Tests\Feature;

use App\Connector\WordPressGateway;
use App\Models\Site;
use App\Services\SeoManagerService;
use Tests\TestCase;

final class SeoProviderStateClosureTest extends TestCase
{
    public function test_provider_states_distinguish_enabled_disabled_unsupported_and_temporary_unavailability(): void
    {
        $seo = new SeoManagerService(new ProviderStateWordPressGateway([]));

        $enabled = $seo->providerState('yoast-seo', true, true);
        $disabled = $seo->providerState('rank-math', false, true);
        $unavailable = $seo->providerState('yoast-seo', true, false);
        $unsupported = $seo->providerState('all-in-one-seo', true, true);
        $native = $seo->providerState(null);

        $this->assertSame('SUPPORTED_ENABLED', $enabled['state']);
        $this->assertContains('seo_title', $enabled['writable']);
        $this->assertSame('SUPPORTED_DISABLED', $disabled['state']);
        $this->assertSame([], $disabled['writable']);
        $this->assertSame('TEMPORARILY_UNAVAILABLE', $unavailable['state']);
        $this->assertSame([], $unavailable['writable']);
        $this->assertSame('UNSUPPORTED', $unsupported['state']);
        $this->assertSame(['title', 'slug'], $unsupported['writable']);
        $this->assertSame('WORDPRESS_NATIVE', $native['state']);
    }

    public function test_authoritative_remote_provider_flags_drive_truthful_provider_state(): void
    {
        $gateway = new ProviderStateWordPressGateway([
            'title' => 'Remote title',
            'slug' => 'remote-title',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
            'seo_canonical' => 'https://example.test/remote-title',
            'seo_robots' => ['index', 'follow'],
            'seo_provider' => 'rank-math',
            'seo_provider_enabled' => 'true',
            'seo_provider_available' => 'false',
            'content' => 'Readable content.',
            'modified_at' => '2026-08-28T00:00:00+00:00',
        ]);
        $seo = new SeoManagerService($gateway);

        $inspection = $seo->inspectRemote(new Site(['name' => 'Remote', 'url' => 'https://example.test']), 'post', 41);

        $this->assertTrue($inspection['authoritative']);
        $this->assertSame('rank-math', $inspection['provider']['provider']);
        $this->assertSame('TEMPORARILY_UNAVAILABLE', $inspection['provider']['state']);
        $this->assertSame([], $inspection['provider']['writable']);
    }
}

final class ProviderStateWordPressGateway implements WordPressGateway
{
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
