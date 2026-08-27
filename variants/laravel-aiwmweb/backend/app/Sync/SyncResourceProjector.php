<?php

namespace App\Sync;

use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class SyncResourceProjector
{
    public function remoteId(array $row): int
    {
        return (int) ($row['id'] ?? data_get($row, 'data.id', 0));
    }

    public function remoteHash(array $row): string
    {
        return $this->hash($row);
    }

    public function remoteVersion(array $row): ?string
    {
        $value = $row['version'] ?? $row['modified_gmt'] ?? $row['modified'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function remoteModifiedAt(array $row): ?Carbon
    {
        return $this->date($row['modified_gmt'] ?? $row['modified'] ?? null);
    }

    public function findLocal(int $siteId, string $resource, int $remoteId): ?Model
    {
        return match ($resource) {
            'posts' => ContentItem::query()->where('site_id', $siteId)->where('type', 'post')->where('remote_id', $remoteId)->first(),
            'pages' => ContentItem::query()->where('site_id', $siteId)->where('type', 'page')->where('remote_id', $remoteId)->first(),
            'media' => MediaItem::query()->where('site_id', $siteId)->where('remote_id', $remoteId)->first(),
            'comments' => Comment::query()->where('site_id', $siteId)->where('remote_id', $remoteId)->first(),
            'categories' => TaxonomyTerm::query()->where('site_id', $siteId)->where('taxonomy', 'category')->where('remote_id', $remoteId)->first(),
            'tags' => TaxonomyTerm::query()->where('site_id', $siteId)->where('taxonomy', 'post_tag')->where('remote_id', $remoteId)->first(),
            default => throw new InvalidArgumentException("Unsupported sync resource: {$resource}"),
        };
    }

    public function localHash(Model $model): string
    {
        return $this->hash($this->localPayload($model));
    }

    public function localPayload(Model $model): array
    {
        if ($model instanceof ContentItem) {
            return [
                'title' => $model->title,
                'slug' => $model->slug,
                'content' => $model->body,
                'excerpt' => $model->excerpt,
                'status' => $model->status,
                'featured_media' => $model->featured_media_remote_id,
                'template' => $model->template,
                'comment_status' => $model->comment_status,
                'ping_status' => $model->ping_status,
                'format' => $model->format,
                'sticky' => (bool) $model->sticky,
                'date_gmt' => $model->published_at?->toIso8601String(),
            ];
        }

        if ($model instanceof MediaItem) {
            return [
                'title' => $model->title,
                'slug' => $model->slug,
                'alt_text' => $model->alt_text,
                'caption' => $model->caption,
                'description' => $model->description,
            ];
        }

        if ($model instanceof Comment) {
            return [
                'post' => $model->content_remote_id,
                'parent' => $model->parent_remote_id,
                'content' => $model->body,
                'status' => $model->status,
            ];
        }

        if ($model instanceof TaxonomyTerm) {
            return [
                'name' => $model->name,
                'slug' => $model->slug,
                'description' => $model->description,
                'parent' => $model->parent_remote_id,
            ];
        }

        throw new InvalidArgumentException('Unsupported local sync model.');
    }

    public function project(int $siteId, string $resource, array $row): Model
    {
        $id = $this->remoteId($row);
        if ($id < 1) {
            throw new InvalidArgumentException('Remote row has no valid id.');
        }

        $hash = $this->remoteHash($row);

        if (in_array($resource, ['posts', 'pages'], true)) {
            $model = ContentItem::query()->firstOrNew([
                'site_id' => $siteId,
                'type' => $resource === 'posts' ? 'post' : 'page',
                'remote_id' => $id,
            ]);
            $model->fill([
                'slug' => $row['slug'] ?? null,
                'title' => $this->rendered($row['title'] ?? null),
                'body' => $this->rawOrRendered($row['content'] ?? null),
                'excerpt' => $this->rawOrRendered($row['excerpt'] ?? null),
                'status' => $row['status'] ?? 'draft',
                'author_remote_id' => $row['author'] ?? null,
                'featured_media_remote_id' => $row['featured_media'] ?? null,
                'link' => $row['link'] ?? null,
                'template' => $row['template'] ?? null,
                'comment_status' => $row['comment_status'] ?? null,
                'ping_status' => $row['ping_status'] ?? null,
                'format' => $row['format'] ?? null,
                'sticky' => (bool) ($row['sticky'] ?? false),
                'published_at' => $this->date($row['date_gmt'] ?? null),
                'scheduled_at' => ($row['status'] ?? '') === 'future' ? $this->date($row['date_gmt'] ?? null) : null,
                'remote_modified_at' => $this->remoteModifiedAt($row),
                'remote_version' => $this->remoteVersion($row),
                'remote_hash' => $hash,
                'synced_at' => now(),
                'stale' => false,
                'metadata' => Arr::except($row, ['content', 'excerpt']),
            ])->save();

            return $model;
        }

        if ($resource === 'media') {
            $model = MediaItem::query()->firstOrNew(['site_id' => $siteId, 'remote_id' => $id]);
            $model->fill([
                'title' => $this->rendered($row['title'] ?? null),
                'slug' => $row['slug'] ?? null,
                'mime_type' => $row['mime_type'] ?? null,
                'media_type' => $row['media_type'] ?? null,
                'source_url' => $row['source_url'] ?? null,
                'alt_text' => $row['alt_text'] ?? null,
                'caption' => $this->rawOrRendered($row['caption'] ?? null),
                'description' => $this->rawOrRendered($row['description'] ?? null),
                'metadata' => $row['media_details'] ?? [],
                'remote_hash' => $hash,
                'remote_modified_at' => $this->remoteModifiedAt($row),
                'synced_at' => now(),
                'processing_state' => 'ready',
            ])->save();

            return $model;
        }

        if ($resource === 'comments') {
            $model = Comment::query()->firstOrNew(['site_id' => $siteId, 'remote_id' => $id]);
            $model->fill([
                'content_remote_id' => $row['post'] ?? null,
                'parent_remote_id' => $row['parent'] ?? null,
                'author_name' => $row['author_name'] ?? null,
                'author_email' => $row['author_email'] ?? null,
                'body' => $this->rawOrRendered($row['content'] ?? null),
                'status' => $row['status'] ?? null,
                'link' => $row['link'] ?? null,
                'remote_created_at' => $this->date($row['date_gmt'] ?? null),
                'remote_modified_at' => $this->remoteModifiedAt($row),
                'remote_hash' => $hash,
                'synced_at' => now(),
                'metadata' => Arr::except($row, ['content']),
            ])->save();

            return $model;
        }

        $taxonomy = $resource === 'categories' ? 'category' : 'post_tag';
        $model = TaxonomyTerm::query()->firstOrNew([
            'site_id' => $siteId,
            'taxonomy' => $taxonomy,
            'remote_id' => $id,
        ]);
        $model->fill([
            'name' => $row['name'] ?? '',
            'slug' => $row['slug'] ?? '',
            'description' => $row['description'] ?? null,
            'parent_remote_id' => $row['parent'] ?? null,
            'usage_count' => (int) ($row['count'] ?? 0),
            'remote_hash' => $hash,
            'remote_modified_at' => $this->remoteModifiedAt($row),
            'synced_at' => now(),
            'metadata' => $row,
        ])->save();

        return $model;
    }

    public function markRemoteDeleted(Model $model): void
    {
        if ($model instanceof ContentItem) {
            $model->forceFill(['stale' => true])->save();
        }
    }

    private function rendered(mixed $value): ?string
    {
        return is_array($value) ? ($value['rendered'] ?? $value['raw'] ?? null) : ($value === null ? null : (string) $value);
    }

    private function rawOrRendered(mixed $value): ?string
    {
        return is_array($value) ? ($value['raw'] ?? $value['rendered'] ?? null) : ($value === null ? null : (string) $value);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');
    }

    private function hash(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
        unset($item);

        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
