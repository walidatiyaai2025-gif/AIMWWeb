<?php

namespace App\Diagnostics;

use App\Models\Site;
use App\Models\SiteCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Explicitly authorised, disposable smoke test for the native WordPress REST path.
 *
 * This is not a claim that UI workflows or Connector-signed writes passed.
 * Never accept content IDs, payloads, WordPress URLs or credentials from the caller.
 * No write is automatically retried: an ambiguous response may already have mutated WP.
 */
final class WordPressWriteSmokeService
{
    public const RESOURCES = ['posts', 'pages', 'categories', 'tags', 'comments', 'media'];

    public function run(Site $site, SiteCredential $credential, string $resource): array
    {
        if (! in_array($resource, self::RESOURCES, true)) {
            return ['state' => 'not_tested', 'reason' => 'unsupported_resource'];
        }

        $marker = 'aiwm-probe-'.strtolower(Str::random(16));
        $objects = [];
        $stage = 'create';
        $passed = false;
        $uncertain = false;
        $failure = 'none';

        try {
            if ($resource === 'comments') {
                $parent = $this->write($site, $credential, 'posts', null, [
                    'status' => 'draft', 'title' => $marker.'-parent',
                ]);
                $parentId = $this->createdId($parent);
                $objects[] = ['resource' => 'posts', 'id' => $parentId];
                $this->read($site, $credential, 'posts', $parentId);
                $created = $this->write($site, $credential, 'comments', null, [
                    'post' => $parentId, 'content' => $marker.'-create', 'status' => 'hold',
                ]);
            } elseif ($resource === 'media') {
                // A 1x1 PNG generated in memory; no user files or existing media are touched.
                $png = base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==',
                    true,
                );
                if ($png === false) {
                    throw new \RuntimeException('fixture_invalid');
                }
                $created = $this->http($site, $credential)
                    ->attach('file', $png, $marker.'.png', ['Content-Type' => 'image/png'])
                    ->post($this->url($site, 'media'))->throw()->json();
            } else {
                $created = $this->write($site, $credential, $resource, null, match ($resource) {
                    'posts', 'pages' => [
                        'status' => 'draft', 'title' => $marker.'-create',
                        'content' => 'Temporary automated WordPress validation item.',
                    ],
                    'categories', 'tags' => ['name' => $marker.'-create'],
                });
            }

            $remoteId = $this->createdId($created);
            $objects[] = ['resource' => $resource, 'id' => $remoteId];

            $stage = 'read_create';
            $initial = $this->read($site, $credential, $resource, $remoteId);
            $this->assertMarker($initial, $resource, $marker.'-create', $resource === 'media');

            $stage = 'save';
            $changes = match ($resource) {
                'posts', 'pages' => ['title' => $marker.'-saved'],
                'categories', 'tags' => ['name' => $marker.'-saved'],
                'comments' => ['content' => $marker.'-saved'],
                'media' => ['alt_text' => $marker.'-saved'],
            };
            $saved = $this->write($site, $credential, $resource, $remoteId, $changes);
            if (! is_array($saved) || (int) ($saved['id'] ?? 0) !== $remoteId) {
                throw new \RuntimeException('save_response_invalid');
            }

            $stage = 'read_save';
            $verified = $this->read($site, $credential, $resource, $remoteId);
            $this->assertMarker($verified, $resource, $marker.'-saved');
            $passed = true;
        } catch (Throwable) {
            // A POST may have reached WordPress despite a lost response. Never replay it.
            $uncertain = $stage === 'create';
            $failure = match ($stage) {
                'create' => 'remote_create_not_confirmed',
                'read_create' => 'created_record_not_verified',
                'save' => 'remote_save_not_confirmed',
                'read_save' => 'saved_record_not_verified',
                default => 'remote_probe_failed',
            };
        }

        $cleanupFailed = [];
        foreach (array_reverse($objects) as $object) {
            // Every ID here came from this request's successful WordPress CREATE response.
            // Never delete an ID supplied by the browser or from existing app records.
            $cleaned = false;
            try {
                $reply = $this->http($site, $credential)->delete(
                    $this->url($site, $object['resource']).'/'.$object['id'],
                    ['force' => true],
                );
                if ($reply->successful() || $reply->status() === 404) {
                    $check = $this->http($site, $credential)->get(
                        $this->url($site, $object['resource']).'/'.$object['id'],
                        ['context' => 'edit'],
                    );
                    $cleaned = $check->status() === 404;
                }
            } catch (Throwable) {
                // No blind retry of DELETE; a lost reply requires operator reconciliation.
            }
            if (! $cleaned) {
                $cleanupFailed[] = [
                    'resource' => $object['resource'],
                    'remote_id' => $object['id'],
                ];
            }
        }

        return [
            'schema' => 'aiwm.wordpress-write-smoke.v1',
            'transport' => 'native_wordpress_rest',
            'resource' => $resource,
            'state' => $cleanupFailed !== [] || $uncertain ? 'needs_reconciliation' : ($passed ? 'passed' : 'failed'),
            'create_verified' => $passed,
            'save_verified' => $passed,
            'delete_verified' => $objects !== [] && $cleanupFailed === [] && ! $uncertain,
            'reason' => $cleanupFailed !== [] ? 'cleanup_not_verified' : ($uncertain ? 'create_result_ambiguous' : $failure),
            'cleanup_required' => $cleanupFailed !== [] || $uncertain,
            // Authorized UI only: use these hints for manual recovery. Never export them.
            'recovery' => $cleanupFailed !== [] || $uncertain ? [
                'marker' => $marker,
                'known_objects' => $cleanupFailed,
            ] : null,
        ];
    }

    private function http(Site $site, SiteCredential $credential): \Illuminate\Http\Client\PendingRequest
    {
        return Http::connectTimeout(4)->timeout(12)->withoutRedirecting()
            ->acceptJson()->withBasicAuth((string) $credential->username, (string) $credential->secret_value);
    }

    private function url(Site $site, string $resource): string
    {
        return rtrim((string) $site->url, '/').'/wp-json/wp/v2/'.$resource;
    }

    private function write(Site $site, SiteCredential $credential, string $resource, ?int $id, array $payload): array
    {
        $url = $this->url($site, $resource).($id === null ? '' : '/'.$id);
        $json = $this->http($site, $credential)->post($url, $payload)->throw()->json();
        if (! is_array($json)) {
            throw new \RuntimeException('malformed_wordpress_write');
        }

        return $json;
    }

    private function createdId(mixed $row): int
    {
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        if (! is_numeric($id) || (int) $id < 1) {
            throw new \RuntimeException('missing_created_id');
        }
        return (int) $id;
    }

    private function read(Site $site, SiteCredential $credential, string $resource, int $id): array
    {
        $row = $this->http($site, $credential)->get(
            $this->url($site, $resource).'/'.$id, ['context' => 'edit'],
        )->throw()->json();
        if (! is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
            throw new \RuntimeException('remote_id_mismatch');
        }
        return $row;
    }

    private function assertMarker(array $row, string $resource, string $expected, bool $initialMedia = false): void
    {
        if ($initialMedia) {
            // WordPress may rename a generated file; authoritative object ID + read suffice.
            return;
        }
        $value = match ($resource) {
            'posts', 'pages' => data_get($row, 'title.raw', data_get($row, 'title.rendered', '')),
            'categories', 'tags' => $row['name'] ?? '',
            'comments' => data_get($row, 'content.raw', data_get($row, 'content.rendered', '')),
            'media' => $row['alt_text'] ?? '',
        };
        if (! is_string($value) || ! str_contains($value, $expected)) {
            throw new \RuntimeException('remote_marker_mismatch');
        }
    }
}
