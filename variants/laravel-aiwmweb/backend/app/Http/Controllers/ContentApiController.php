<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Content\ContentConflictException;
use App\Content\ContentPlatformService;
use App\Content\Remote\ContentRemoteDriver;
use App\Jobs\BulkCommentModerationJob;
use App\Jobs\BulkContentMutationJob;
use App\Jobs\BulkTaxonomyAssignmentJob;
use App\Jobs\ContentTransferJob;
use App\Jobs\MediaUploadJob;
use App\Jobs\SyncContentJob;
use App\Models\Comment;
use App\Models\ContentConflict;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentSyncState;
use App\Models\ContentTransfer;
use App\Models\MediaItem;
use App\Models\TaxonomyTerm;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class ContentApiController extends Controller
{
    public function __construct(private readonly ContentPlatformService $content, private readonly ContentRemoteDriver $remote, private readonly TenantContext $tenant) {}

    public function index(Request $request, TenantAuthorizer $auth, int $site, string $type): JsonResponse
    {
        $auth->authorize('content.view');
        abort_unless(in_array($type, ['post','page'], true), 404);
        return response()->json($this->content->content($site, $type, $request->only(['search','status','per_page'])));
    }

    public function show(TenantAuthorizer $auth, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.view');
        $item = ContentItem::query()->where('site_id',$site)->with(['revisions','terms'])->findOrFail($content);
        return response()->json($item);
    }

    public function store(Request $request, TenantAuthorizer $auth, int $site, string $type): JsonResponse
    {
        $auth->authorize('content.edit');
        abort_unless(in_array($type, ['post','page'], true), 404);
        $payload = $this->contentPayload($request);
        $result = $this->content->mutateContent($site, $type, null, 'create', $payload);
        return response()->json($result, 201);
    }

    public function update(Request $request, TenantAuthorizer $auth, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id',$site)->findOrFail($content);
        return $this->mutationResponse(fn () => $this->content->mutateContent($site, $item->type, $item->remote_id, 'update', $this->contentPayload($request), $this->expected($request, $item)));
    }

    public function state(Request $request, TenantAuthorizer $auth, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id',$site)->findOrFail($content);
        $data = $request->validate(['action'=>['required',Rule::in(['draft','pending','publish','schedule','trash','restore'])],'date_gmt'=>'nullable|date']);
        return $this->mutationResponse(fn () => $this->content->mutateContent($site, $item->type, $item->remote_id, $data['action'], ['date_gmt'=>$data['date_gmt'] ?? null], $this->expected($request, $item)));
    }

    public function destroy(Request $request, TenantAuthorizer $auth, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id',$site)->findOrFail($content);
        return $this->mutationResponse(fn () => $this->content->mutateContent($site, $item->type, $item->remote_id, 'delete', [], $this->expected($request, $item)));
    }

    public function bulk(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['ids'=>'required|array|min:1|max:500','ids.*'=>'integer','action'=>['required',Rule::in(['draft','pending','publish','trash','restore'])],'payload'=>'array']);
        $count = ContentItem::query()->where('site_id',$site)->whereIn('id',$data['ids'])->count();
        abort_unless($count === count(array_unique($data['ids'])), 422, 'Bulk selection includes unavailable content.');
        BulkContentMutationJob::dispatch($this->tenant->id(), $site, array_values(array_unique($data['ids'])), $data['action'], $data['payload'] ?? []);
        return response()->json(['state'=>'queued','count'=>$count], 202);
    }

    public function revisions(TenantAuthorizer $auth, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.view');
        $item = ContentItem::query()->where('site_id',$site)->findOrFail($content);
        return response()->json($item->revisions()->latest()->paginate(50));
    }

    public function compareRevisions(TenantAuthorizer $auth, int $site, int $content, int $from, int $to): JsonResponse
    {
        $auth->authorize('content.view');
        $item = ContentItem::query()->where('site_id',$site)->findOrFail($content);
        $a = ContentRevision::query()->where('site_id',$site)->where('content_item_id',$item->id)->findOrFail($from);
        $b = ContentRevision::query()->where('site_id',$site)->where('content_item_id',$item->id)->findOrFail($to);
        return response()->json(['from'=>$a,'to'=>$b,'diff'=>$this->content->compare($a,$b)]);
    }

    public function restoreRevision(TenantAuthorizer $auth, int $site, int $content, int $revision): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentItem::query()->where('site_id',$site)->findOrFail($content);
        $rev = ContentRevision::query()->where('site_id',$site)->where('content_item_id',$item->id)->findOrFail($revision);
        return $this->mutationResponse(fn () => $this->content->restoreRevision($site,$item,$rev));
    }

    public function media(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $q = MediaItem::query()->where('site_id',$site);
        if ($search=trim((string)$request->query('search'))) $q->where(fn($x)=>$x->where('title','like',"%{$search}%")->orWhere('alt_text','like',"%{$search}%"));
        if ($mime=$request->query('mime_type')) $q->where('mime_type','like',$mime.'%');
        return response()->json($q->latest('remote_modified_at')->paginate(min(max((int)$request->query('per_page',30),1),100)));
    }

    public function uploadMedia(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate(['file'=>'required|file|max:204800','alt_text'=>'nullable|string|max:500','caption'=>'nullable|string|max:5000','title'=>'nullable|string|max:500']);
        $file = $data['file'];
        $path = $file->store("content-uploads/{$this->tenant->id()}/{$site}", 'local');
        $transfer = ContentTransfer::query()->create(['site_id'=>$site,'kind'=>'media-upload','state'=>'queued','progress'=>0,'storage_path'=>$path,'options'=>['name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'metadata'=>array_filter(['alt_text'=>$data['alt_text'] ?? null,'caption'=>$data['caption'] ?? null,'title'=>$data['title'] ?? null])]]);
        MediaUploadJob::dispatch($this->tenant->id(), $transfer->id);
        return response()->json($transfer, 202);
    }

    public function updateMedia(Request $request, TenantAuthorizer $auth, int $site, int $media): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = MediaItem::query()->where('site_id',$site)->findOrFail($media);
        $data = $request->validate(['alt_text'=>'nullable|string|max:500','caption'=>'nullable|string|max:5000','description'=>'nullable|string','title'=>'nullable|string|max:500']);
        $result = $this->remote->mutate($site,'media',$item->remote_id,'update',$data);
        SyncContentJob::dispatch($this->tenant->id(),$site,false);
        return response()->json($result);
    }

    public function deleteMedia(TenantAuthorizer $auth, int $site, int $media): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = MediaItem::query()->where('site_id',$site)->findOrFail($media);
        $used = ContentItem::query()->where('site_id',$site)->where('featured_media_remote_id',$item->remote_id)->pluck('id');
        abort_if($used->isNotEmpty(),409,'Media is referenced as featured media; detach it before deletion.');
        $result = $this->remote->mutate($site,'media',$item->remote_id,'delete');
        $item->delete();
        return response()->json($result);
    }

    public function comments(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $q = Comment::query()->where('site_id',$site);
        if ($status=$request->query('status')) $q->where('status',$status);
        if ($search=trim((string)$request->query('search'))) $q->where(fn($x)=>$x->where('author_name','like',"%{$search}%")->orWhere('author_email','like',"%{$search}%")->orWhere('body','like',"%{$search}%"));
        return response()->json($q->latest('remote_created_at')->paginate(min(max((int)$request->query('per_page',25),1),100)));
    }

    public function commentAction(Request $request, TenantAuthorizer $auth, int $site, int $comment): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = Comment::query()->where('site_id',$site)->findOrFail($comment);
        $data = $request->validate(['action'=>['required',Rule::in(['approve','unapprove','spam','unspam','trash','restore','delete'])]]);
        return $this->mutationResponse(fn () => $this->content->mutateComment($site,$item->remote_id,$data['action']));
    }

    public function replyComment(Request $request, TenantAuthorizer $auth, int $site, int $comment): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = Comment::query()->where('site_id',$site)->findOrFail($comment);
        $data = $request->validate(['content'=>'required|string|max:20000']);
        return response()->json($this->remote->mutate($site,'comments',null,'create',['post'=>$item->content_remote_id,'parent'=>$item->remote_id,'content'=>$data['content']]),201);
    }

    public function bulkComments(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data=$request->validate(['ids'=>'required|array|min:1|max:500','ids.*'=>'integer','action'=>['required',Rule::in(['approve','unapprove','spam','unspam','trash','restore','delete'])]]);
        $count=Comment::query()->where('site_id',$site)->whereIn('id',$data['ids'])->count();
        abort_unless($count===count(array_unique($data['ids'])),422,'Bulk selection includes unavailable comments.');
        BulkCommentModerationJob::dispatch($this->tenant->id(),$site,array_values(array_unique($data['ids'])),$data['action']);
        return response()->json(['state'=>'queued','count'=>$count],202);
    }

    public function taxonomy(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $q=TaxonomyTerm::query()->where('site_id',$site);
        if ($taxonomy=$request->query('taxonomy')) $q->where('taxonomy',$taxonomy);
        return response()->json($q->orderBy('taxonomy')->orderBy('name')->paginate(min(max((int)$request->query('per_page',100),1),200)));
    }

    public function discoverTaxonomy(TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        return response()->json($this->remote->semantic($site,'taxonomy.discover'));
    }

    public function createTerm(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data=$request->validate(['taxonomy'=>'required|string|max:96','name'=>'required|string|max:255','slug'=>'nullable|string|max:255','description'=>'nullable|string','parent'=>'nullable|integer']);
        $taxonomy=array_shift($data);
        return response()->json($this->content->mutateTerm($site,$taxonomy,null,'create',$data),201);
    }

    public function updateTerm(Request $request, TenantAuthorizer $auth, int $site, int $term): JsonResponse
    {
        $auth->authorize('content.edit');
        $item=TaxonomyTerm::query()->where('site_id',$site)->findOrFail($term);
        $data=$request->validate(['name'=>'sometimes|string|max:255','slug'=>'sometimes|string|max:255','description'=>'nullable|string','parent'=>'nullable|integer']);
        return response()->json($this->content->mutateTerm($site,$item->taxonomy,$item->remote_id,'update',$data));
    }

    public function deleteTerm(TenantAuthorizer $auth, int $site, int $term): JsonResponse
    {
        $auth->authorize('content.edit');
        $item=TaxonomyTerm::query()->where('site_id',$site)->findOrFail($term);
        return response()->json($this->content->mutateTerm($site,$item->taxonomy,$item->remote_id,'delete'));
    }

    public function assignTerms(Request $request, TenantAuthorizer $auth, int $site, int $content): JsonResponse
    {
        $auth->authorize('content.edit');
        $item=ContentItem::query()->where('site_id',$site)->findOrFail($content);
        $data=$request->validate(['term_ids'=>'present|array','term_ids.*'=>'integer']);
        $this->content->assignTerms($site,$item,array_values(array_unique($data['term_ids'])));
        return response()->json(['assigned'=>$item->terms()->pluck('taxonomy_terms.id')]);
    }

    public function bulkAssignTerms(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data=$request->validate(['content_ids'=>'required|array|min:1|max:500','content_ids.*'=>'integer','term_ids'=>'present|array','term_ids.*'=>'integer']);
        $count=ContentItem::query()->where('site_id',$site)->whereIn('id',$data['content_ids'])->count();
        abort_unless($count===count(array_unique($data['content_ids'])),422,'Bulk selection includes unavailable content.');
        BulkTaxonomyAssignmentJob::dispatch($this->tenant->id(),$site,array_values(array_unique($data['content_ids'])),array_values(array_unique($data['term_ids'])));
        return response()->json(['state'=>'queued','count'=>$count],202);
    }

    public function sync(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $full=$request->boolean('full');
        SyncContentJob::dispatch($this->tenant->id(),$site,$full);
        return response()->json(['state'=>'queued','full'=>$full],202);
    }

    public function syncStatus(TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        return response()->json(ContentSyncState::query()->where('site_id',$site)->orderBy('resource')->get());
    }

    public function conflicts(TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        return response()->json(ContentConflict::query()->where('site_id',$site)->latest()->paginate(50));
    }

    public function resolveConflict(Request $request, TenantAuthorizer $auth, int $site, int $conflict): JsonResponse
    {
        $auth->authorize('content.edit');
        $item=ContentConflict::query()->where('site_id',$site)->where('status','open')->findOrFail($conflict);
        $data=$request->validate(['resolution'=>['required',Rule::in(['remote_wins','dismiss'])]]);
        if ($data['resolution']==='remote_wins') SyncContentJob::dispatch($this->tenant->id(),$site,false);
        $item->update(['status'=>'resolved','resolution'=>$data['resolution'],'resolved_at'=>now()]);
        return response()->json($item->fresh());
    }

    public function export(TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $transfer=ContentTransfer::query()->create(['site_id'=>$site,'kind'=>'export','state'=>'queued','progress'=>0,'options'=>[]]);
        ContentTransferJob::dispatch($this->tenant->id(),$transfer->id);
        return response()->json($transfer,202);
    }

    public function import(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data=$request->validate(['file'=>'required|file|mimes:json,txt|max:102400']);
        $path=$data['file']->store("content-imports/{$this->tenant->id()}/{$site}",'local');
        $transfer=ContentTransfer::query()->create(['site_id'=>$site,'kind'=>'import','state'=>'queued','progress'=>0,'storage_path'=>$path,'options'=>[]]);
        ContentTransferJob::dispatch($this->tenant->id(),$transfer->id);
        return response()->json($transfer,202);
    }

    public function transfer(TenantAuthorizer $auth, int $site, int $transfer): JsonResponse
    {
        $auth->authorize('content.view');
        return response()->json(ContentTransfer::query()->where('site_id',$site)->findOrFail($transfer));
    }

    private function contentPayload(Request $request): array
    {
        return $request->validate(['title'=>'sometimes|string|max:1000','slug'=>'sometimes|nullable|string|max:255','content'=>'sometimes|nullable|string','excerpt'=>'sometimes|nullable|string','status'=>['sometimes',Rule::in(['draft','pending','publish','future','private'])],'date_gmt'=>'sometimes|nullable|date','featured_media'=>'sometimes|nullable|integer','author'=>'sometimes|nullable|integer','categories'=>'sometimes|array','categories.*'=>'integer','tags'=>'sometimes|array','tags.*'=>'integer','template'=>'sometimes|nullable|string|max:255','comment_status'=>['sometimes',Rule::in(['open','closed'])],'ping_status'=>['sometimes',Rule::in(['open','closed'])],'format'=>'sometimes|nullable|string|max:64','sticky'=>'sometimes|boolean']);
    }

    private function expected(Request $request, ContentItem $item): array
    {
        $data=$request->validate(['expected_hash'=>'sometimes|nullable|string|size:64','expected_modified_at'=>'sometimes|nullable|date','expected_version'=>'sometimes|nullable|string|max:255']);
        return ['hash'=>$data['expected_hash'] ?? $item->remote_hash,'modified_at'=>$data['expected_modified_at'] ?? $item->remote_modified_at?->toIso8601String(),'version'=>$data['expected_version'] ?? $item->remote_version];
    }

    private function mutationResponse(callable $callback): JsonResponse
    {
        try { return response()->json($callback()); }
        catch (ContentConflictException $e) { return response()->json(['message'=>$e->getMessage(),'conflict_id'=>$e->conflictId],409); }
    }
}
