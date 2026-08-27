<?php

namespace App\Content;

use App\Content\Remote\ContentRemoteDriver;
use App\Models\AuditEvent;
use App\Models\Comment;
use App\Models\ContentConflict;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentSyncState;
use App\Models\MediaItem;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ContentPlatformService
{
    public function __construct(private readonly ContentRemoteDriver $remote) {}

    public function content(int $siteId, string $type, array $filters = []): LengthAwarePaginator
    {
        $q = ContentItem::query()->where('site_id', $siteId)->where('type', $type);
        if ($search = trim((string) ($filters['search'] ?? ''))) $q->where(fn ($x) => $x->where('title','like',"%{$search}%")->orWhere('slug','like',"%{$search}%")->orWhere('body','like',"%{$search}%"));
        if ($status = $filters['status'] ?? null) $q->where('status', $status);
        return $q->latest('remote_modified_at')->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
    }

    public function sync(int $siteId, bool $full = false): array
    {
        $resources = ['posts','pages','media','categories','tags','comments'];
        $summary = [];
        foreach ($resources as $index => $resource) {
            $state = ContentSyncState::query()->firstOrCreate(['site_id'=>$siteId,'resource'=>$resource], ['state'=>'idle']);
            $state->update(['state'=>'running','progress'=>(int) floor(($index / count($resources)) * 100),'attempts'=>$state->attempts + 1,'started_at'=>now(),'last_error'=>null]);
            try {
                $query = $full || ! $state->last_remote_modified_at ? ['per_page'=>100] : ['per_page'=>100,'modified_after'=>$state->last_remote_modified_at->toIso8601String()];
                $rows = $this->remote->list($siteId, $resource, $query);
                $changed = 0; $maxModified = $state->last_remote_modified_at;
                foreach ($rows as $row) {
                    if (! is_array($row)) continue;
                    $changed += $this->upsertRemote($siteId, $resource, $row) ? 1 : 0;
                    $modified = $this->date($row['modified_gmt'] ?? $row['modified'] ?? null);
                    if ($modified && (! $maxModified || $modified->greaterThan($maxModified))) $maxModified = $modified;
                }
                $state->update(['state'=>'succeeded','progress'=>100,'completed_at'=>now(),'last_remote_modified_at'=>$maxModified]);
                $summary[$resource] = ['received'=>count($rows),'changed'=>$changed];
            } catch (\Throwable $e) {
                $state->update(['state'=>'failed','last_error'=>$e->getMessage(),'completed_at'=>now()]);
                throw $e;
            }
        }
        $this->audit('content.sync.completed', 'site', $siteId, ['full'=>$full,'summary'=>$summary]);
        return $summary;
    }

    public function mutateContent(int $siteId, string $type, ?int $remoteId, string $action, array $payload, array $expected = []): array
    {
        $resource = $type === 'post' ? 'posts' : 'pages';
        $item = $remoteId ? ContentItem::query()->where('site_id',$siteId)->where('type',$type)->where('remote_id',$remoteId)->first() : null;
        if ($item && ! in_array($action, ['create'], true)) $this->guardConflict($siteId, $resource, $item, $expected);
        if ($item && in_array($action, ['update','publish','draft','pending','schedule','trash','delete','restore'], true)) $this->snapshot($item, 'local-before-mutation');
        $payload = $this->contentPayload($action, $payload);
        $remote = $this->remote->mutate($siteId, $resource, $remoteId, $action, $payload);
        if ($action === 'delete') { $item?->delete(); }
        elseif ($action === 'trash' && $item) { $item->update(['status'=>'trash','synced_at'=>now()]); }
        else { $this->upsertRemote($siteId, $resource, $remote); }
        $this->audit('content.'.$action, $type, $remoteId, ['payload_keys'=>array_keys($payload)]);
        return $remote;
    }

    public function restoreRevision(int $siteId, ContentItem $item, ContentRevision $revision): array
    {
        abort_unless($item->site_id === $siteId && $revision->site_id === $siteId && $revision->content_item_id === $item->id, 404);
        return $this->mutateContent($siteId, $item->type, $item->remote_id, 'update', Arr::only($revision->snapshot, ['title','slug','content','excerpt','status','featured_media','categories','tags','template','comment_status','ping_status','format','sticky','date_gmt']), ['hash'=>$item->remote_hash,'modified_at'=>$item->remote_modified_at?->toIso8601String()]);
    }

    public function mutateComment(int $siteId, int $remoteId, string $action, array $payload = []): array
    {
        $comment = Comment::query()->where('site_id',$siteId)->where('remote_id',$remoteId)->firstOrFail();
        $this->guardConflict($siteId, 'comments', $comment, ['hash'=>$comment->remote_hash,'modified_at'=>$comment->remote_modified_at?->toIso8601String()]);
        $result = $this->remote->mutate($siteId, 'comments', $remoteId, $action, $payload);
        if ($action === 'delete') $comment->delete(); else $this->upsertRemote($siteId, 'comments', $result);
        $this->audit('comment.'.$action, 'comment', $remoteId, []);
        return $result;
    }

    public function mutateTerm(int $siteId, string $taxonomy, ?int $remoteId, string $action, array $payload = []): array
    {
        $resource = $taxonomy === 'category' ? 'categories' : ($taxonomy === 'post_tag' ? 'tags' : $taxonomy);
        $result = in_array($resource, ['categories','tags'], true)
            ? $this->remote->mutate($siteId, $resource, $remoteId, $action, $payload)
            : $this->remote->semantic($siteId, 'taxonomy.mutate', compact('taxonomy','remoteId','action','payload'));
        if ($action === 'delete') TaxonomyTerm::query()->where('site_id',$siteId)->where('taxonomy',$taxonomy)->where('remote_id',$remoteId)->delete();
        elseif (is_array($result)) $this->upsertRemote($siteId, $resource, $result, $taxonomy);
        $this->audit('taxonomy.'.$action, $taxonomy, $remoteId, []);
        return $result;
    }

    public function assignTerms(int $siteId, ContentItem $item, array $termIds): void
    {
        abort_unless($item->site_id === $siteId, 404);
        $terms = TaxonomyTerm::query()->where('site_id',$siteId)->whereIn('id',$termIds)->get();
        abort_unless($terms->count() === count(array_unique($termIds)), 422, 'One or more taxonomy terms are outside this site or tenant.');
        $pivot = $terms->mapWithKeys(fn ($term) => [$term->id=>['tenant_id'=>$item->tenant_id,'site_id'=>$siteId]])->all();
        $item->terms()->sync($pivot);
    }

    public function compare(ContentRevision $a, ContentRevision $b): array
    {
        $keys = array_unique(array_merge(array_keys($a->snapshot), array_keys($b->snapshot)));
        $diff = [];
        foreach ($keys as $key) if (($a->snapshot[$key] ?? null) !== ($b->snapshot[$key] ?? null)) $diff[$key] = ['from'=>$a->snapshot[$key] ?? null,'to'=>$b->snapshot[$key] ?? null];
        return $diff;
    }

    private function upsertRemote(int $siteId, string $resource, array $row, ?string $customTaxonomy = null): bool
    {
        $id = (int) ($row['id'] ?? data_get($row,'data.id',0));
        if ($id < 1) return false;
        $hash = $this->hash($row);
        if (in_array($resource, ['posts','pages'], true)) {
            $type = $resource === 'posts' ? 'post' : 'page';
            $model = ContentItem::query()->firstOrNew(['site_id'=>$siteId,'type'=>$type,'remote_id'=>$id]);
            $old = $model->remote_hash;
            $model->fill(['slug'=>$row['slug'] ?? null,'title'=>$this->rendered($row['title'] ?? null),'body'=>$this->rawOrRendered($row['content'] ?? null),'excerpt'=>$this->rawOrRendered($row['excerpt'] ?? null),'status'=>$row['status'] ?? 'draft','author_remote_id'=>$row['author'] ?? null,'featured_media_remote_id'=>$row['featured_media'] ?? null,'link'=>$row['link'] ?? null,'template'=>$row['template'] ?? null,'comment_status'=>$row['comment_status'] ?? null,'ping_status'=>$row['ping_status'] ?? null,'format'=>$row['format'] ?? null,'sticky'=>(bool)($row['sticky'] ?? false),'published_at'=>$this->date($row['date_gmt'] ?? null),'scheduled_at'=>($row['status'] ?? '') === 'future' ? $this->date($row['date_gmt'] ?? null) : null,'remote_modified_at'=>$this->date($row['modified_gmt'] ?? $row['modified'] ?? null),'remote_version'=>(string)($row['version'] ?? $row['modified_gmt'] ?? ''),'remote_hash'=>$hash,'synced_at'=>now(),'stale'=>false,'metadata'=>Arr::except($row,['content','excerpt'])])->save();
            if ($old && $old !== $hash) $this->snapshot($model, 'wordpress-sync');
            return $old !== $hash;
        }
        if ($resource === 'media') {
            $model = MediaItem::query()->firstOrNew(['site_id'=>$siteId,'remote_id'=>$id]); $old=$model->remote_hash;
            $model->fill(['title'=>$this->rendered($row['title'] ?? null),'slug'=>$row['slug'] ?? null,'mime_type'=>$row['mime_type'] ?? null,'media_type'=>$row['media_type'] ?? null,'source_url'=>$row['source_url'] ?? null,'alt_text'=>$row['alt_text'] ?? null,'caption'=>$this->rawOrRendered($row['caption'] ?? null),'description'=>$this->rawOrRendered($row['description'] ?? null),'metadata'=>$row['media_details'] ?? [],'remote_hash'=>$hash,'remote_modified_at'=>$this->date($row['modified_gmt'] ?? null),'synced_at'=>now(),'processing_state'=>'ready'])->save(); return $old !== $hash;
        }
        if ($resource === 'comments') {
            $model = Comment::query()->firstOrNew(['site_id'=>$siteId,'remote_id'=>$id]); $old=$model->remote_hash;
            $model->fill(['content_remote_id'=>$row['post'] ?? null,'parent_remote_id'=>$row['parent'] ?? null,'author_name'=>$row['author_name'] ?? null,'author_email'=>$row['author_email'] ?? null,'body'=>$this->rawOrRendered($row['content'] ?? null),'status'=>$row['status'] ?? null,'link'=>$row['link'] ?? null,'remote_created_at'=>$this->date($row['date_gmt'] ?? null),'remote_modified_at'=>$this->date($row['modified_gmt'] ?? null),'remote_hash'=>$hash,'synced_at'=>now(),'metadata'=>Arr::except($row,['content'])])->save(); return $old !== $hash;
        }
        $taxonomy = $customTaxonomy ?? ($resource === 'categories' ? 'category' : ($resource === 'tags' ? 'post_tag' : $resource));
        $model = TaxonomyTerm::query()->firstOrNew(['site_id'=>$siteId,'taxonomy'=>$taxonomy,'remote_id'=>$id]); $old=$model->remote_hash;
        $model->fill(['name'=>$row['name'] ?? '', 'slug'=>$row['slug'] ?? '', 'description'=>$row['description'] ?? null,'parent_remote_id'=>$row['parent'] ?? null,'usage_count'=>(int)($row['count'] ?? 0),'remote_hash'=>$hash,'remote_modified_at'=>$this->date($row['modified_gmt'] ?? null),'synced_at'=>now(),'metadata'=>$row])->save(); return $old !== $hash;
    }

    private function guardConflict(int $siteId, string $resource, Model $local, array $expected): void
    {
        $remote = $this->remote->get($siteId, $resource, (int) $local->remote_id);
        $remoteHash = $this->hash($remote); $remoteModified = $this->date($remote['modified_gmt'] ?? $remote['modified'] ?? null); $remoteVersion = (string)($remote['version'] ?? $remote['modified_gmt'] ?? '');
        $expectedHash = $expected['hash'] ?? $local->remote_hash; $expectedModified = $this->date($expected['modified_at'] ?? $local->remote_modified_at); $expectedVersion = $expected['version'] ?? $local->remote_version;
        $changed = ($expectedHash && ! hash_equals((string)$expectedHash, $remoteHash)) || ($expectedModified && $remoteModified && ! $expectedModified->equalTo($remoteModified)) || ($expectedVersion && $remoteVersion && (string)$expectedVersion !== $remoteVersion);
        if (! $changed) return;
        $conflict = ContentConflict::query()->create(['site_id'=>$siteId,'entity_type'=>class_basename($local),'entity_id'=>$local->id,'remote_id'=>$local->remote_id,'expected_modified_at'=>$expectedModified,'remote_modified_at'=>$remoteModified,'expected_version'=>$expectedVersion,'remote_version'=>$remoteVersion,'expected_hash'=>$expectedHash,'remote_hash'=>$remoteHash,'local_snapshot'=>$local->toArray(),'remote_snapshot'=>$remote]);
        throw new ContentConflictException($conflict->id);
    }

    private function snapshot(ContentItem $item, string $source): ContentRevision
    {
        $snapshot = ['title'=>$item->title,'slug'=>$item->slug,'content'=>$item->body,'excerpt'=>$item->excerpt,'status'=>$item->status,'featured_media'=>$item->featured_media_remote_id,'template'=>$item->template,'comment_status'=>$item->comment_status,'ping_status'=>$item->ping_status,'format'=>$item->format,'sticky'=>$item->sticky,'date_gmt'=>$item->published_at?->toIso8601String()];
        return ContentRevision::query()->create(['site_id'=>$item->site_id,'content_item_id'=>$item->id,'snapshot'=>$snapshot,'content_hash'=>$this->hash($snapshot),'remote_modified_at'=>$item->remote_modified_at,'source'=>$source]);
    }

    private function contentPayload(string $action, array $payload): array
    {
        if (in_array($action, ['publish','draft','pending'], true)) $payload['status'] = $action === 'publish' ? 'publish' : $action;
        if ($action === 'schedule') $payload['status'] = 'future';
        return $payload;
    }

    private function audit(string $event, string $subjectType, int|string|null $subjectId, array $metadata): void
    {
        AuditEvent::query()->create(['actor_user_id'=>auth()->id(),'event'=>$event,'subject_type'=>$subjectType,'subject_id'=>$subjectId === null ? null : (string)$subjectId,'metadata'=>$metadata,'occurred_at'=>now()]);
    }

    private function rendered(mixed $value): ?string { return is_array($value) ? ($value['rendered'] ?? $value['raw'] ?? null) : ($value === null ? null : (string)$value); }
    private function rawOrRendered(mixed $value): ?string { return is_array($value) ? ($value['raw'] ?? $value['rendered'] ?? null) : ($value === null ? null : (string)$value); }
    private function hash(array $value): string { ksort($value); return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
    private function date(mixed $value): ?\Illuminate\Support\Carbon { if (! $value) return null; return $value instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($value) : \Illuminate\Support\Carbon::parse($value, 'UTC'); }
}
