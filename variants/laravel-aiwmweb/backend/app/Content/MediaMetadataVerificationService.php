<?php

namespace App\Content;

use App\Content\Remote\ContentRemoteDriver;
use RuntimeException;

/**
 * A WordPress write is not considered completed until a fresh remote read
 * confirms the media identity and each submitted metadata field.
 */
final class MediaMetadataVerificationService
{
    private readonly ContentRemoteDriver $remote;

    public function __construct(ContentRemoteDriver $remote)
    {
        $this->remote = $remote;
    }

    /** @param array<string, string|null> $metadata
     *  @return array<string, mixed>
     */
    public function save(int $siteId, int $remoteId, array $metadata): array
    {
        $this->remote->mutate($siteId, 'media', $remoteId, 'update', $metadata);
        $authoritative = $this->remote->get($siteId, 'media', $remoteId);

        if ((int) ($authoritative['id'] ?? 0) !== $remoteId) {
            throw new RuntimeException('WordPress media verification returned an unexpected identity.');
        }

        foreach ($metadata as $field => $expected) {
            // Native WP REST returns raw values in the authenticated edit context.
            $found = $authoritative[$field] ?? null;
            if (in_array($field, ['title', 'caption', 'description'], true) && is_array($found)) {
                $found = $found['raw'] ?? null;
            }
            if (is_string($found) === false || $this->normalize($found) !== $this->normalize((string) ($expected ?? ''))) {
                throw new RuntimeException('WordPress media metadata verification failed for '.$field.'.');
            }
        }

        return [
            'verified' => true,
            'remote_id' => $remoteId,
            'source' => 'wordpress-authoritative-read',
        ];
    }

    private function normalize(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
