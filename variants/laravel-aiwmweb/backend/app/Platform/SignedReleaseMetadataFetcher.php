<?php

namespace App\Platform;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bounded transport for GitHub release metadata. Authenticity belongs to the
 * caller's detached Ed25519 signature verification, not to this transport.
 */
final class SignedReleaseMetadataFetcher
{
    private const ASSET_API = 'https://api.github.com/repos/walidatiyaai2025-gif/AIMWWeb/releases/assets/';

    /**
     * @param  array{url: string, size: int, id: ?int}  $asset
     */
    public function download(array $asset, int $limit): string
    {
        $deadline = microtime(true) + max(20, min(90, (int) config('system_updates.metadata_budget_seconds', 50)));
        $connectTimeout = max(3, min(12, (int) config('system_updates.metadata_connect_timeout', 8)));
        $urls = [$asset['url']];
        $assetId = (int) ($asset['id'] ?? 0);
        $urls[] = $assetId > 0 ? self::ASSET_API.$assetId : $asset['url'];

        foreach ($urls as $index => $url) {
            $remaining = (int) ceil($deadline - microtime(true));
            if ($remaining <= 2) {
                break;
            }

            $timeout = max(3, min(30, $remaining));
            try {
                $response = Http::accept('application/octet-stream')
                    ->connectTimeout(min($connectTimeout, $timeout - 1))
                    ->timeout($timeout)
                    ->get($url);
            } catch (ConnectionException $exception) {
                continue;
            }

            if ($response->successful()) {
                $bytes = $response->body();
                if (strlen($bytes) > $limit) {
                    throw new RuntimeException('Release metadata exceeds the allowed size.');
                }
                if ($index > 0 && str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')) {
                    throw new RuntimeException('GitHub asset API returned metadata instead of signed asset bytes.');
                }

                return $bytes;
            }

            if (in_array($response->status(), [408, 429, 500, 502, 503, 504], true) === false) {
                throw new RuntimeException('Release metadata download failed (HTTP '.$response->status().').');
            }
        }

        throw new RuntimeException('GitHub release metadata is temporarily unavailable. Retry or upload a signed update ZIP.');
    }
}
