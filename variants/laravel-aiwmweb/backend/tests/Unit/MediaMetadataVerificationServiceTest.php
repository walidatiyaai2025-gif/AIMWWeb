<?php

namespace Tests\Unit;

use App\Content\MediaMetadataVerificationService;
use App\Content\Remote\ContentRemoteDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaMetadataVerificationServiceTest extends TestCase
{
    public function test_a_real_wordpress_write_requires_a_subsequent_authoritative_read(): void
    {
        $remote = $this->driver([
            'id' => 105,
            'title' => ['raw' => 'Revised title'],
            'slug' => 'new-slug',
            'alt_text' => 'Concise alt',
            'caption' => ['raw' => 'Caption'],
            'description' => ['raw' => 'Description'],
        ]);
        $service = new MediaMetadataVerificationService($remote);
        $result = $service->save(7, 105, [
            'title' => 'Revised title', 'slug' => 'new-slug',
            'alt_text' => 'Concise alt', 'caption' => 'Caption', 'description' => 'Description',
        ]);
        self::assertTrue($result['verified']);
        self::assertSame(105, $result['remote_id']);
        self::assertSame(['mutate:7:media:105', 'get:7:media:105'], $remote->calls);
    }

    public function test_a_stale_or_foreign_remote_identity_is_never_accepted(): void
    {
        $remote = $this->driver(['id' => 999, 'title' => ['raw' => 'Revised title']]);
        $this->expectException(RuntimeException::class);
        (new MediaMetadataVerificationService($remote))->save(7, 105, ['title' => 'Revised title']);
    }

    public function test_a_remote_metadata_mismatch_does_not_claim_success(): void
    {
        $remote = $this->driver(['id' => 105, 'alt_text' => 'Old alt']);
        $this->expectException(RuntimeException::class);
        (new MediaMetadataVerificationService($remote))->save(7, 105, ['alt_text' => 'New alt']);
    }

    private function driver(array $read): ContentRemoteDriver
    {
        return new class($read) implements ContentRemoteDriver {
            public array $calls = [];
            public function __construct(private array $read) {}
            public function list(int $siteId, string $resource, array $query = []): array { return []; }
            public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
            {
                $this->calls[] = "get:{$siteId}:{$resource}:{$remoteId}";
                return $this->read;
            }
            public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
            {
                $this->calls[] = "mutate:{$siteId}:{$resource}:{$remoteId}";
                return ['id' => $remoteId];
            }
            public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array { return []; }
            public function semantic(int $siteId, string $operation, array $payload = []): array { return []; }
        };
    }
}
