<?php

namespace App\Sync;

use App\Content\Remote\ContentRemoteDriver;
use App\Models\ContentItem;
use App\Sync\Contracts\SyncSiteGuard;
use Carbon\CarbonImmutable;

final class GlobalSynchronizationConflictReviewService
{
    public const OPERATION_ID = 'AIMW-BILL-5887A977D7';

    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly ContentRemoteDriver $remote,
        private readonly SyncSiteGuard $sites,
    ) {}

    public function review(int $siteId): array
    {
        $this->sites->assertAccessible($siteId);

        $local = ContentItem::query()
            ->where('site_id', $siteId)
            ->whereIn('type', ['post', 'page'])
            ->where('stale', false)
            ->orderBy('type')
            ->orderBy('remote_id')
            ->get();

        $lastSync = $local->max('synced_at');
        $remote = array_merge(
            $this->loadAll($siteId, 'posts'),
            $this->loadAll($siteId, 'pages'),
        );

        $localMap = [];
        foreach ($local as $item) {
            if ((int) $item->remote_id < 1) {
                continue;
            }
            $localMap[$this->key((string) $item->type, (int) $item->remote_id)] = $this->localComparable($item);
        }

        $remoteMap = [];
        foreach ($remote as $entry) {
            $remoteId = (int) ($entry['id'] ?? 0);
            $type = ($entry['_aiwm_resource'] ?? '') === 'pages' ? 'page' : 'post';
            if ($remoteId < 1) {
                continue;
            }
            $remoteMap[$this->key($type, $remoteId)] = $this->remoteComparable($type, $entry);
        }

        $conflicts = [];
        foreach ($localMap as $key => $localVersion) {
            if (! array_key_exists($key, $remoteMap)) {
                $conflicts[] = [
                    'content_type' => $localVersion['content_type'],
                    'wordpress_id' => $localVersion['wordpress_id'],
                    'kind' => 'RemoteDeleted',
                    'local' => $this->version($localVersion),
                    'remote' => null,
                ];
                continue;
            }

            $remoteVersion = $remoteMap[$key];
            if ($this->meaningfullyDifferent($localVersion, $remoteVersion)) {
                $conflicts[] = [
                    'content_type' => $localVersion['content_type'],
                    'wordpress_id' => $localVersion['wordpress_id'],
                    'kind' => 'RemoteUpdated',
                    'local' => $this->version($localVersion),
                    'remote' => $this->version($remoteVersion),
                ];
            }
        }

        usort($conflicts, static function (array $left, array $right): int {
            $leftModified = (string) ($left['remote']['modified_at'] ?? $left['local']['modified_at'] ?? '');
            $rightModified = (string) ($right['remote']['modified_at'] ?? $right['local']['modified_at'] ?? '');
            return $rightModified <=> $leftModified
                ?: ($left['content_type'] <=> $right['content_type'])
                ?: ($left['wordpress_id'] <=> $right['wordpress_id']);
        });

        $remoteAdditions = count(array_diff_key($remoteMap, $localMap));
        $remoteUpdates = count(array_filter($conflicts, fn (array $item): bool => $item['kind'] === 'RemoteUpdated'));
        $remoteDeletions = count(array_filter($conflicts, fn (array $item): bool => $item['kind'] === 'RemoteDeleted'));

        return [
            'operation_id' => self::OPERATION_ID,
            'has_baseline' => $localMap !== [] || $lastSync !== null,
            'local_synchronized_at' => $lastSync?->toIso8601String(),
            'remote_additions' => $remoteAdditions,
            'remote_updates' => $remoteUpdates,
            'remote_deletions' => $remoteDeletions,
            'has_conflicts' => $conflicts !== [],
            'conflicts' => $conflicts,
        ];
    }

    private function loadAll(int $siteId, string $resource): array
    {
        $items = [];
        for ($page = 1; ; $page++) {
            $batch = $this->remote->list($siteId, $resource, [
                'context' => 'edit',
                'per_page' => self::PAGE_SIZE,
                'page' => $page,
                'orderby' => 'modified',
                'order' => 'desc',
            ]);

            foreach ($batch as $row) {
                if (is_array($row)) {
                    $row['_aiwm_resource'] = $resource;
                    $items[] = $row;
                }
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return $items;
    }

    private function localComparable(ContentItem $item): array
    {
        return [
            'content_type' => (string) $item->type,
            'wordpress_id' => (int) $item->remote_id,
            'title' => (string) ($item->title ?? ''),
            'slug' => (string) ($item->slug ?? ''),
            'status' => (string) ($item->status ?? ''),
            'link' => (string) ($item->link ?? ''),
            'rendered_content' => (string) ($item->body ?? ''),
            'rendered_excerpt' => (string) ($item->excerpt ?? ''),
            'modified_at' => $item->remote_modified_at?->toIso8601String(),
        ];
    }

    private function remoteComparable(string $type, array $row): array
    {
        return [
            'content_type' => $type,
            'wordpress_id' => (int) ($row['id'] ?? 0),
            'title' => $this->rendered($row['title'] ?? null),
            'slug' => (string) ($row['slug'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'link' => (string) ($row['link'] ?? ''),
            'rendered_content' => $this->rawOrRendered($row['content'] ?? null),
            'rendered_excerpt' => $this->rawOrRendered($row['excerpt'] ?? null),
            'modified_at' => $this->instant($row['modified_gmt'] ?? $row['modified'] ?? null),
        ];
    }

    private function meaningfullyDifferent(array $left, array $right): bool
    {
        foreach (['content_type', 'wordpress_id', 'title', 'slug', 'status', 'link', 'rendered_content', 'rendered_excerpt', 'modified_at'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function version(array $item): array
    {
        return [
            'title' => $item['title'],
            'slug' => $item['slug'],
            'status' => $item['status'],
            'link' => $item['link'],
            'rendered_content' => $item['rendered_content'],
            'rendered_excerpt' => $item['rendered_excerpt'],
            'modified_at' => $item['modified_at'],
        ];
    }

    private function key(string $type, int $remoteId): string
    {
        return strtolower($type).':'.$remoteId;
    }

    private function rendered(mixed $value): string
    {
        if (!is_array($value)) {
            return $value === null ? '' : (string) $value;
        }
        return (string) ($value['rendered'] ?? $value['raw'] ?? '');
    }

    private function rawOrRendered(mixed $value): string
    {
        if (! is_array($value)) {
            return $value === null ? '' : (string) $value;
        }
        return (string) ($value['raw'] ?? $value['rendered'] ?? '');
    }

    private function instant(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601String();
    }
}
