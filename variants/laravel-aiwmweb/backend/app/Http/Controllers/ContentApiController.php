<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Content\ContentConflictException;
use App\Content\ContentPlatformService;
use App\Content\MediaDeleteService;
use App\Content\Remote\ContentRemoteDriver;
use App\Jobs\BulkCommentModerationJob;
use App\Jobs\BulkContentMutationJob;
use App\Jobs\BulkTaxonomyAssignmentJob;
use App\Jobs\ContentTransferJob;
use App\Jobs\MediaUploadJob;
use App\Jobs\SyncContentJob;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\ContentConflict;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentSyncState;
use App\Models\ContentTransfer;
use App\Models\MediaItem;
use App\Models\Site;
use App\Models\TaxonomyTerm;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ContentApiController extends Controller
{
    public const MEDIA_DELETE_OPERATION_ID = 'AIMW-BILL-4DCB58743D';

    public const CONTENT_EDITOR_SAVE_OPERATION_ID = 'AIMW-BILL-42F7590F00';

    public const CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID = 'AIMW-BILL-F5686193FE';

    public function __construct(
        private readonly ContentPlatformService $content,
        private readonly ContentRemoteDriver $remote,
        private readonly TenantContext $tenant,
        private readonly MediaDeleteService $mediaDelete,
    )
    {
    }

    public function index(Request $request, TenantAuthorizer $auth, string $tenant, int $site, string $type): JsonResponse
    {
        $auth->authorize('content.view');
        abort_unless(in_array($type, ['post', 'page'], true), 404);

        return response()->json($this->content->content($site, $type, $request->only(['search', 'status', 'per_page'])));
    }

    public function show(TenantAuthorizer $auth, string $tenant, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.view');
        $item = ContentItem::query()->where('site_id', $site)->with(['revisions', 'terms'])->findOrFail($content);

        return response()->json($item);
    }

    public function editor(TenantAuthorizer $auth, string $tenant, int $site, string $type, int $wordpressId): JsonResponse
    {
        $auth->authorize('content.view');
        abort_unless(in_array($type, ['post', 'page'], true), 404);

        $item = ContentItem::query()
            ->where('site_id', $site)
            ->where('type', $type)
            ->where('remote_id', $wordpressId)
            ->firstOrFail();

        return response()->json($this->editorSnapshot($item));
    }

    public function saveEditor(Request $request, TenantAuthorizer $auth, string $tenant, int $site, string $type, int $wordpressId): JsonResponse
    {
        $auth->authorize('content.edit');
        abort_unless(in_array($type, ['post', 'page'], true), 404);

        $callerOwned = [
            'tenant', 'tenant_id', 'site', 'site_id', 'content_id', 'remote_id',
            'wordpress_id', 'user_id', 'owner_user_id', 'actor_user_id',
        ];
        abort_if(array_intersect(array_keys($request->all()), $callerOwned) !== [], 422, 'Content save does not accept caller-owned identity fields.');

        $item = ContentItem::query()
            ->where('site_id', $site)
            ->where('type', $type)
            ->where('remote_id', $wordpressId)
            ->firstOrFail();

        $payload = $request->validate([
            'title' => 'required|string|max:1000',
            'slug' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'excerpt' => 'nullable|string',
            'status' => ['required', Rule::in(['draft', 'pending', 'publish', 'future', 'private'])],
            'date_gmt' => 'nullable|date',
            'featured_media' => 'nullable|integer|min:0',
            'categories' => 'present|array',
            'categories.*' => 'integer|min:1',
            'tags' => 'present|array',
            'tags.*' => 'integer|min:1',
            'template' => 'nullable|string|max:255',
            'comment_status' => ['required', Rule::in(['open', 'closed'])],
            'ping_status' => ['required', Rule::in(['open', 'closed'])],
            'format' => 'nullable|string|max:64',
            'sticky' => 'required|boolean',
            'expected_hash' => 'nullable|string|size:64',
            'expected_modified_at' => 'nullable|date',
            'expected_version' => 'nullable|string|max:255',
        ]);

        $expected = [
            'hash' => $payload['expected_hash'] ?? $item->remote_hash,
            'modified_at' => $payload['expected_modified_at'] ?? $item->remote_modified_at?->toIso8601String(),
            'version' => $payload['expected_version'] ?? $item->remote_version,
        ];
        unset($payload['expected_hash'], $payload['expected_modified_at'], $payload['expected_version']);

        return $this->mutationResponse(function () use ($site, $type, $wordpressId, $payload, $expected): array {
            $this->content->mutateContent($site, $type, $wordpressId, 'update', $payload, $expected);
            $reconciled = $this->content->reconcileContentItem($site, $type, $wordpressId);

            return $this->editorSnapshot($reconciled);
        });
    }

    public function submitEditorForApproval(Request $request, TenantAuthorizer $auth, string $tenant, int $site, string $type, int $wordpressId): JsonResponse
    {
        $auth->authorize('content.edit');
        abort_unless(in_array($type, ['post', 'page'], true), 404);

        $callerOwned = [
            'tenant', 'tenant_id', 'site', 'site_id', 'content_id', 'remote_id',
            'wordpress_id', 'user_id', 'owner_user_id', 'actor_user_id',
        ];
        abort_if(array_intersect(array_keys($request->all()), $callerOwned) !== [], 422, 'Content approval does not accept caller-owned identity fields.');

        $item = ContentItem::query()
            ->where('site_id', $site)
            ->where('type', $type)
            ->where('remote_id', $wordpressId)
            ->firstOrFail();
        $ownedSite = Site::query()->findOrFail($site);

        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'title' => 'required|string|max:1000',
            'slug' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'excerpt' => 'nullable|string',
            'status' => ['required', Rule::in(['draft', 'pending', 'publish', 'future', 'private'])],
            'date_gmt' => 'nullable|date',
            'featured_media' => 'nullable|integer|min:0',
            'categories' => 'present|array',
            'categories.*' => 'integer|min:1',
            'tags' => 'present|array',
            'tags.*' => 'integer|min:1',
            'template' => 'nullable|string|max:255',
            'comment_status' => ['required', Rule::in(['open', 'closed'])],
            'ping_status' => ['required', Rule::in(['open', 'closed'])],
            'format' => 'nullable|string|max:64',
            'sticky' => 'required|boolean',
            'expected_hash' => 'nullable|string|size:64',
            'expected_modified_at' => 'nullable|date',
            'expected_version' => 'nullable|string|max:255',
        ]);

        $expected = [
            'hash' => $data['expected_hash'] ?? $item->remote_hash,
            'modified_at' => $data['expected_modified_at'] ?? $item->remote_modified_at?->toIso8601String(),
            'version' => $data['expected_version'] ?? $item->remote_version,
        ];

        return $this->mutationResponse(function () use ($request, $site, $type, $wordpressId, $ownedSite, $data, $expected): array {
            $baseline = $this->content->assertContentVersion($site, $type, $wordpressId, $expected);
            $before = $this->editorApprovalState($baseline);
            $proposed = $this->editorProposedApprovalState($baseline, $data);
            $existing = Approval::query()->where('request_key', $data['request_key'])->first();

            if ($existing !== null) {
                abort_if(
                    $existing->source_operation_id !== self::CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID
                    || (int) $existing->site_id !== $site
                    || $existing->before_state != $before
                    || $existing->proposed_state != $proposed,
                    409,
                    'Approval request key is already bound to a different proposal.',
                );

                return ['data' => $this->serializeEditorApproval($existing), 'replayed' => true];
            }

            $actor = $request->user();
            $actorLabel = trim((string) ($actor->name ?? ''));
            if ($actorLabel === '') {
                $actorLabel = (string) $actor->getKey();
            }
            $plainTitle = trim(html_entity_decode(strip_tags((string) $data['title'])));
            $title = "Update {$type} #{$wordpressId}".($plainTitle !== '' ? " — {$plainTitle}" : '');

            $attributes = [
                'suggestion_id' => null,
                'site_id' => $site,
                'site_name' => $ownedSite->name,
                'actor_user_id' => $actor->getKey(),
                'status' => 'PENDING',
                'source_operation_id' => self::CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID,
                'operation_type' => 'WordPressContentUpdateOperation',
                'title' => $title,
                'actor_label' => $actorLabel,
                'risk_level' => 'Medium',
                'request_key' => $data['request_key'],
                'before_state' => $before,
                'proposed_state' => $proposed,
            ];

            try {
                $approval = Approval::query()->create($attributes);
            } catch (QueryException $exception) {
                $approval = Approval::query()->where('request_key', $data['request_key'])->first();
                if ($approval === null) {
                    throw $exception;
                }
                abort_if(
                    $approval->source_operation_id !== self::CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID
                    || (int) $approval->site_id !== $site
                    || $approval->before_state != $before
                    || $approval->proposed_state != $proposed,
                    409,
                    'Approval request key is already bound to a different proposal.',
                );

                return ['data' => $this->serializeEditorApproval($approval), 'replayed' => true];
            }

            return ['data' => $this->serializeEditorApproval($approval), 'replayed' => false];
        }, 201);
    }

    public function store(Request $request, TenantAuthorizer $auth, string $tenant, int $site, string $type): JsonResponse
    {
        $auth->authorize('content.edit');
        abort_unless(in_array($type, ['post', 'page'], true), 404);
        $payload = $this->contentPayload($request);
        $result = $this->content->mutateContent($site, $type, null, 'create', $payload);

        return response()->json($result, 201);
    }

    public function update(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);

        return $this->mutationResponse(fn () => $this->content->mutateContent($site, $item->type, $item->remote_id, 'update', $this->contentPayload($request), $this->expected($request, $item)));
    }

    public function state(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);
        $data = $request->validate(['action' => ['required', Rule::in(['draft', 'pending', 'publish', 'schedule', 'trash', 'restore'])], 'date_gmt' => 'nullable|date']);

        return $this->mutationResponse(fn () => $this->content->mutateContent($site, $item->type, $item->remote_id, $data['action'], ['date_gmt' => $data['date_gmt'] ?? null], $this->expected($request, $item)));
    }

    public function destroy(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);

        return $this->mutationResponse(fn () => $this->content->mutateContent($site, $item->type, $item->remote_id, 'delete', [], $this->expected($request, $item)));
    }

    public function bulk(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'action' => ['required', Rule::in(['draft', 'pending', 'publish', 'trash', 'restore'])], 'payload' => 'array']);
        $count = ContentItem::query()->where('site_id', $site)->whereIn('id', $data['ids'])->count();
        abort_unless($count === count(array_unique($data['ids'])), 422, 'Bulk selection includes unavailable content.');
        BulkContentMutationJob::dispatch($this->tenant->id(), $site, array_values(array_unique($data['ids'])), $data['action'], $data['payload'] ?? []);

        return response()->json(['state' => 'queued', 'count' => $count], 202);
    }

    public function revisions(TenantAuthorizer $auth, string $tenant, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.view');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);

        return response()->json($item->revisions()->latest()->paginate(50));
    }

    public function compareRevisions(TenantAuthorizer $auth, string $tenant, int $site, int $content, int $from, int $to): JsonResponse
    {
        $auth->authorize('content.view');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);
        $a = ContentRevision::query()->where('site_id', $site)->where('content_item_id', $item->id)->findOrFail($from);
        $b = ContentRevision::query()->where('site_id', $site)->where('content_item_id', $item->id)->findOrFail($to);

        return response()->json(['from' => $a, 'to' => $b, 'diff' => $this->content->compare($a, $b)]);
    }

    public function restoreRevision(TenantAuthorizer $auth, string $tenant, int $site, int $content, int $revision): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);
        $rev = ContentRevision::query()->where('site_id', $site)->where('content_item_id', $item->id)->findOrFail($revision);

        return $this->mutationResponse(fn () => $this->content->restoreRevision($site, $item, $rev));
    }

    public function media(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $q = MediaItem::query()->where('site_id', $site);
        if ($search = trim((string) $request->query('search'))) {
            $q->where(fn ($x) => $x->where('title', 'like', "%{$search}%")->orWhere('alt_text', 'like', "%{$search}%"));
        }
        if ($mime = $request->query('mime_type')) {
            $q->where('mime_type', 'like', $mime.'%');
        }

        return response()->json($q->latest('remote_modified_at')->paginate(min(max((int) $request->query('per_page', 30), 1), 100)));
    }

    public function uploadMedia(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['file' => 'required|file|max:204800', 'alt_text' => 'nullable|string|max:500', 'caption' => 'nullable|string|max:5000', 'title' => 'nullable|string|max:500']);
        $file = $data['file'];
        $path = $file->store("content-uploads/{$this->tenant->id()}/{$site}", 'local');
        $transfer = ContentTransfer::query()->create(['site_id' => $site, 'kind' => 'media-upload', 'state' => 'queued', 'progress' => 0, 'storage_path' => $path, 'options' => ['name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'metadata' => array_filter(['alt_text' => $data['alt_text'] ?? null, 'caption' => $data['caption'] ?? null, 'title' => $data['title'] ?? null])]]);
        MediaUploadJob::dispatch($this->tenant->id(), $transfer->id);

        return response()->json($transfer, 202);
    }

    public function updateMedia(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $media): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = MediaItem::query()->where('site_id', $site)->findOrFail($media);
        $data = $request->validate(['alt_text' => 'nullable|string|max:500', 'caption' => 'nullable|string|max:5000', 'description' => 'nullable|string', 'title' => 'nullable|string|max:500']);
        $result = $this->remote->mutate($site, 'media', $item->remote_id, 'update', $data);
        SyncContentJob::dispatch($this->tenant->id(), $site, false);

        return response()->json($result);
    }

    public function deleteMedia(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $media): JsonResponse
    {
        $auth->authorize('content.edit');
        abort_if($request->request->count() > 0, 422, 'Media delete does not accept caller-owned fields.');

        return response()->json($this->mediaDelete->deletePermanently($site, $media));
    }

    public function comments(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $q = Comment::query()->where('site_id', $site);
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($search = trim((string) $request->query('search'))) {
            $q->where(fn ($x) => $x->where('author_name', 'like', "%{$search}%")->orWhere('author_email', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"));
        }

        return response()->json($q->latest('remote_created_at')->paginate(min(max((int) $request->query('per_page', 25), 1), 100)));
    }

    public function commentAction(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $comment): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = Comment::query()->where('site_id', $site)->findOrFail($comment);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'unapprove', 'spam', 'unspam', 'trash', 'restore', 'delete'])]]);

        return $this->mutationResponse(fn () => $this->content->mutateComment($site, $item->remote_id, $data['action']));
    }

    public function replyComment(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $comment): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = Comment::query()->where('site_id', $site)->findOrFail($comment);
        $data = $request->validate(['content' => 'required|string|max:20000']);

        return response()->json($this->remote->mutate($site, 'comments', null, 'create', ['post' => $item->content_remote_id, 'parent' => $item->remote_id, 'content' => $data['content']]), 201);
    }

    public function bulkComments(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'action' => ['required', Rule::in(['approve', 'unapprove', 'spam', 'unspam', 'trash', 'restore', 'delete'])]]);
        $count = Comment::query()->where('site_id', $site)->whereIn('id', $data['ids'])->count();
        abort_unless($count === count(array_unique($data['ids'])), 422, 'Bulk selection includes unavailable comments.');
        BulkCommentModerationJob::dispatch($this->tenant->id(), $site, array_values(array_unique($data['ids'])), $data['action']);

        return response()->json(['state' => 'queued', 'count' => $count], 202);
    }

    public function taxonomy(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $q = TaxonomyTerm::query()->where('site_id', $site);
        if ($taxonomy = $request->query('taxonomy')) {
            $q->where('taxonomy', $taxonomy);
        }

        return response()->json($q->orderBy('taxonomy')->orderBy('name')->paginate(min(max((int) $request->query('per_page', 100), 1), 200)));
    }

    public function discoverTaxonomy(TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');

        return response()->json($this->remote->semantic($site, 'taxonomy.discover'));
    }

    public function createTerm(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['taxonomy' => 'required|string|max:96', 'name' => 'required|string|max:255', 'slug' => 'nullable|string|max:255', 'description' => 'nullable|string', 'parent' => 'nullable|integer']);
        $taxonomy = array_shift($data);

        return response()->json($this->content->mutateTerm($site, $taxonomy, null, 'create', $data), 201);
    }

    public function updateTerm(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $term): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = TaxonomyTerm::query()->where('site_id', $site)->findOrFail($term);
        $data = $request->validate(['name' => 'sometimes|string|max:255', 'slug' => 'sometimes|string|max:255', 'description' => 'nullable|string', 'parent' => 'nullable|integer']);

        return response()->json($this->content->mutateTerm($site, $item->taxonomy, $item->remote_id, 'update', $data));
    }

    public function deleteTerm(TenantAuthorizer $auth, string $tenant, int $site, int $term): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = TaxonomyTerm::query()->where('site_id', $site)->findOrFail($term);

        return response()->json($this->content->mutateTerm($site, $item->taxonomy, $item->remote_id, 'delete'));
    }

    public function assignTerms(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id', $site)->findOrFail($content);
        $data = $request->validate(['term_ids' => 'present|array', 'term_ids.*' => 'integer']);
        $this->content->assignTerms($site, $item, array_values(array_unique($data['term_ids'])));

        return response()->json(['assigned' => $item->terms()->pluck('taxonomy_terms.id')]);
    }

    public function bulkAssignTerms(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['content_ids' => 'required|array|min:1|max:500', 'content_ids.*' => 'integer', 'term_ids' => 'present|array', 'term_ids.*' => 'integer']);
        $count = ContentItem::query()->where('site_id', $site)->whereIn('id', $data['content_ids'])->count();
        abort_unless($count === count(array_unique($data['content_ids'])), 422, 'Bulk selection includes unavailable content.');
        BulkTaxonomyAssignmentJob::dispatch($this->tenant->id(), $site, array_values(array_unique($data['content_ids'])), array_values(array_unique($data['term_ids'])));

        return response()->json(['state' => 'queued', 'count' => $count], 202);
    }

    public function sync(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $full = $request->boolean('full');
        SyncContentJob::dispatch($this->tenant->id(), $site, $full);

        return response()->json(['state' => 'queued', 'full' => $full], 202);
    }

    public function syncStatus(TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');

        return response()->json(ContentSyncState::query()->where('site_id', $site)->orderBy('resource')->get());
    }

    public function conflicts(TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');

        return response()->json(ContentConflict::query()->where('site_id', $site)->latest()->paginate(50));
    }

    public function resolveConflict(Request $request, TenantAuthorizer $auth, string $tenant, int $site, int $conflict): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentConflict::query()->where('site_id', $site)->where('status', 'open')->findOrFail($conflict);
        $data = $request->validate(['resolution' => ['required', Rule::in(['remote_wins', 'dismiss'])]]);
        if ($data['resolution'] === 'remote_wins') {
            SyncContentJob::dispatch($this->tenant->id(), $site, false);
        }
        $item->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_at' => now()]);

        return response()->json($item->fresh());
    }

    public function export(TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $transfer = ContentTransfer::query()->create(['site_id' => $site, 'kind' => 'export', 'state' => 'queued', 'progress' => 0, 'options' => []]);
        ContentTransferJob::dispatch($this->tenant->id(), $transfer->id);

        return response()->json($transfer, 202);
    }

    public function import(Request $request, TenantAuthorizer $auth, string $tenant, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['file' => 'required|file|mimes:json,txt|max:102400']);
        $path = $data['file']->store("content-imports/{$this->tenant->id()}/{$site}", 'local');
        $transfer = ContentTransfer::query()->create(['site_id' => $site, 'kind' => 'import', 'state' => 'queued', 'progress' => 0, 'storage_path' => $path, 'options' => []]);
        ContentTransferJob::dispatch($this->tenant->id(), $transfer->id);

        return response()->json($transfer, 202);
    }

    public function transfer(TenantAuthorizer $auth, string $tenant, int $site, int $transfer): JsonResponse
    {
        $auth->authorize('content.view');

        return response()->json(ContentTransfer::query()->where('site_id', $site)->findOrFail($transfer));
    }

    private function editorSnapshot(ContentItem $item): array
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];

        return [
            'id' => (int) $item->id,
            'wordpress_id' => (int) $item->remote_id,
            'type' => (string) $item->type,
            'title' => (string) ($item->title ?? ''),
            'slug' => (string) ($item->slug ?? ''),
            'content' => (string) ($item->body ?? ''),
            'excerpt' => (string) ($item->excerpt ?? ''),
            'status' => (string) ($item->status ?? 'draft'),
            'date_gmt' => $item->published_at?->toIso8601String(),
            'featured_media' => (int) ($item->featured_media_remote_id ?? 0),
            'categories' => array_values(array_map('intval', (array) ($metadata['categories'] ?? []))),
            'tags' => array_values(array_map('intval', (array) ($metadata['tags'] ?? []))),
            'template' => (string) ($item->template ?? ''),
            'comment_status' => (string) ($item->comment_status ?? 'open'),
            'ping_status' => (string) ($item->ping_status ?? 'open'),
            'format' => (string) ($item->format ?? 'standard'),
            'sticky' => (bool) $item->sticky,
            'link' => (string) ($item->link ?? ''),
            'expected_hash' => $item->remote_hash,
            'expected_modified_at' => $item->remote_modified_at?->toIso8601String(),
            'expected_version' => $item->remote_version,
            'reconciled_at' => $item->synced_at?->toIso8601String(),
        ];
    }

    private function editorApprovalState(ContentItem $item): array
    {
        $snapshot = $this->editorSnapshot($item);

        return [
            'content_type' => $snapshot['type'],
            'wordpress_id' => $snapshot['wordpress_id'],
            'title' => $snapshot['title'],
            'slug' => $snapshot['slug'],
            'content' => $snapshot['content'],
            'excerpt' => $snapshot['excerpt'],
            'status' => $snapshot['status'],
            'date_gmt' => $snapshot['date_gmt'],
            'featured_media' => $snapshot['featured_media'],
            'categories' => $snapshot['categories'],
            'tags' => $snapshot['tags'],
            'template' => $snapshot['template'],
            'comment_status' => $snapshot['comment_status'],
            'ping_status' => $snapshot['ping_status'],
            'format' => $snapshot['format'],
            'sticky' => $snapshot['sticky'],
            'expected_hash' => $snapshot['expected_hash'],
            'expected_modified_at' => $snapshot['expected_modified_at'],
            'expected_version' => $snapshot['expected_version'],
        ];
    }

    private function editorProposedApprovalState(ContentItem $baseline, array $data): array
    {
        return [
            'content_type' => (string) $baseline->type,
            'wordpress_id' => (int) $baseline->remote_id,
            'title' => (string) $data['title'],
            'slug' => (string) ($data['slug'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'excerpt' => (string) ($data['excerpt'] ?? ''),
            'status' => (string) $data['status'],
            'date_gmt' => $data['date_gmt'] ?? null,
            'featured_media' => (int) ($data['featured_media'] ?? 0),
            'categories' => array_values(array_map('intval', $data['categories'])),
            'tags' => array_values(array_map('intval', $data['tags'])),
            'template' => (string) ($data['template'] ?? ''),
            'comment_status' => (string) $data['comment_status'],
            'ping_status' => (string) $data['ping_status'],
            'format' => (string) ($data['format'] ?? 'standard'),
            'sticky' => (bool) $data['sticky'],
            'expected_hash' => $data['expected_hash'] ?? $baseline->remote_hash,
            'expected_modified_at' => $data['expected_modified_at'] ?? $baseline->remote_modified_at?->toIso8601String(),
            'expected_version' => $data['expected_version'] ?? $baseline->remote_version,
        ];
    }

    private function sameEditorApprovalState(array $stored, array $candidate): bool
    {
        return hash_equals(
            $this->editorApprovalStateFingerprint($stored),
            $this->editorApprovalStateFingerprint($candidate),
        );
    }

    private function editorApprovalStateFingerprint(array $state): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }

            ksort($value);

            return array_map($normalize, $value);
        };

        return hash(
            'sha256',
            json_encode($normalize($state), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function serializeEditorApproval(Approval $approval): array
    {
        return [
            'id' => (int) $approval->id,
            'status' => (string) $approval->status,
            'site_id' => (int) $approval->site_id,
            'site_name' => (string) $approval->site_name,
            'operation_type' => (string) $approval->operation_type,
            'title' => (string) $approval->title,
            'risk_level' => (string) $approval->risk_level,
            'request_key' => (string) $approval->request_key,
            'created_at' => $approval->created_at?->toISOString(),
        ];
    }

    private function contentPayload(Request $request): array
    {
        return $request->validate(['title' => 'sometimes|string|max:1000', 'slug' => 'sometimes|nullable|string|max:255', 'content' => 'sometimes|nullable|string', 'excerpt' => 'sometimes|nullable|string', 'status' => ['sometimes', Rule::in(['draft', 'pending', 'publish', 'future', 'private'])], 'date_gmt' => 'sometimes|nullable|date', 'featured_media' => 'sometimes|nullable|integer', 'author' => 'sometimes|nullable|integer', 'categories' => 'sometimes|array', 'categories.*' => 'integer', 'tags' => 'sometimes|array', 'tags.*' => 'integer', 'template' => 'sometimes|nullable|string|max:255', 'comment_status' => ['sometimes', Rule::in(['open', 'closed'])], 'ping_status' => ['sometimes', Rule::in(['open', 'closed'])], 'format' => 'sometimes|nullable|string|max:64', 'sticky' => 'sometimes|boolean']);
    }

    private function expected(Request $request, ContentItem $item): array
    {
        $data = $request->validate(['expected_hash' => 'sometimes|nullable|string|size:64', 'expected_modified_at' => 'sometimes|nullable|date', 'expected_version' => 'sometimes|nullable|string|max:255']);

        return ['hash' => $data['expected_hash'] ?? $item->remote_hash, 'modified_at' => $data['expected_modified_at'] ?? $item->remote_modified_at?->toIso8601String(), 'version' => $data['expected_version'] ?? $item->remote_version];
    }

    private function mutationResponse(callable $callback, int $successStatus = 200): JsonResponse
    {
        try {
            $payload = $callback();
            $status = (($payload['replayed'] ?? false) === true) ? 200 : $successStatus;

            return response()->json($payload, $status);
        } catch (ContentConflictException $e) {
            return response()->json(['message' => $e->getMessage(), 'conflict_id' => $e->conflictId], 409);
        }
    }
}