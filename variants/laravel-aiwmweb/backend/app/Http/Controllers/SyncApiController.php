<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\ContentConflict;
use App\Models\SyncBatch;
use App\Models\SyncEvent;
use App\Models\SyncItem;
use App\Models\SyncRun;
use App\Models\SyncSiteLease;
use App\Models\SyncTombstone;
use App\Models\SyncWebhookEvent;
use App\Models\Tenant;
use App\Sync\Contracts\SyncWebhookVerifier;
use App\Sync\SyncRuntimeService;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SyncApiController extends Controller
{
    public function __construct(
        private readonly SyncRuntimeService $runtime,
        private readonly TenantContext $tenant,
        private readonly SyncWebhookVerifier $webhooks,
    ) {}

    public function start(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.edit');
        $data = $request->validate([
            'full' => 'sometimes|boolean',
            'resources' => 'sometimes|array|min:1|max:6',
            'resources.*' => ['string', Rule::in(SyncRuntimeService::RESOURCES)],
        ]);

        try {
            $run = $this->runtime->start(
                $this->tenant->id(),
                $site,
                (bool) ($data['full'] ?? false),
                $data['resources'] ?? SyncRuntimeService::RESOURCES,
                'manual',
                [],
                auth()->id(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['code' => 'SYNC_CONFLICT', 'message' => $exception->getMessage()], 409);
        }

        return response()->json($run, 202);
    }

    public function index(Request $request, TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');
        $query = SyncRun::query()->where('site_id', $site)->latest('id');
        if ($state = $request->query('state')) {
            $query->where('state', $state);
        }

        return response()->json($query->paginate(min(max((int) $request->query('per_page', 25), 1), 100)));
    }

    public function show(TenantAuthorizer $auth, int $site, int $run): JsonResponse
    {
        $auth->authorize('content.view');
        $syncRun = SyncRun::query()->where('site_id', $site)->with(['batches' => fn ($query) => $query->orderBy('id')])->findOrFail($run);

        return response()->json([
            'run' => $syncRun,
            'failed_items' => SyncItem::query()->where('site_id', $site)->where('sync_run_id', $run)->where('state', 'failed')->orderBy('id')->limit(200)->get(),
            'events' => SyncEvent::query()->where('site_id', $site)->where('sync_run_id', $run)->latest('id')->limit(200)->get(),
        ]);
    }

    public function resume(TenantAuthorizer $auth, int $site, int $run): JsonResponse
    {
        $auth->authorize('content.edit');
        $syncRun = SyncRun::query()->where('site_id', $site)->findOrFail($run);

        try {
            $resumed = $this->runtime->resume($this->tenant->id(), $syncRun, auth()->id());
        } catch (RuntimeException $exception) {
            return response()->json(['code' => 'SYNC_CONFLICT', 'message' => $exception->getMessage()], 409);
        }

        return response()->json($resumed, 202);
    }

    public function retryItem(TenantAuthorizer $auth, int $site, int $item): JsonResponse
    {
        $auth->authorize('content.edit');
        $syncItem = SyncItem::query()->where('site_id', $site)->findOrFail($item);
        $this->runtime->retryItem($this->tenant->id(), $syncItem);

        return response()->json($syncItem->fresh(), 202);
    }

    public function conflicts(TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');

        return response()->json(ContentConflict::query()->where('site_id', $site)->latest('id')->paginate(50));
    }

    public function resolveConflict(Request $request, TenantAuthorizer $auth, int $site, int $conflict): JsonResponse
    {
        $auth->authorize('content.edit');
        $item = ContentConflict::query()->where('site_id', $site)->where('status', 'open')->findOrFail($conflict);
        $data = $request->validate([
            'strategy' => ['required', Rule::in(['KEEP_REMOTE', 'KEEP_LOCAL', 'MANUAL', 'RETRY_RECONCILIATION'])],
            'manual_payload' => 'sometimes|array|max:100',
        ]);

        try {
            return response()->json($this->runtime->resolveConflict($item, $data['strategy'], $data['manual_payload'] ?? [], auth()->id()));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['code' => 'INVALID_CONFLICT_RESOLUTION', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['code' => 'SYNC_CONFLICT', 'message' => $exception->getMessage()], 409);
        }
    }

    public function diagnostics(TenantAuthorizer $auth, int $site): JsonResponse
    {
        $auth->authorize('content.view');

        return response()->json([
            'active_run' => SyncRun::query()->where('site_id', $site)->whereIn('state', ['queued', 'running'])->latest('id')->first(),
            'lease' => SyncSiteLease::query()->where('site_id', $site)->first(),
            'open_conflicts' => ContentConflict::query()->where('site_id', $site)->where('status', 'open')->count(),
            'confirmed_tombstones' => SyncTombstone::query()->where('site_id', $site)->whereNotNull('confirmed_deleted_at')->count(),
            'failed_items' => SyncItem::query()->where('site_id', $site)->where('state', 'failed')->count(),
            'recent_webhooks' => SyncWebhookEvent::query()->where('site_id', $site)->latest('id')->limit(20)->get(),
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $event = $this->webhooks->verify($request);
        } catch (Throwable $exception) {
            return response()->json(['code' => 'INVALID_SYNC_WEBHOOK', 'message' => 'Webhook verification failed.'], 401);
        }

        $tenant = Tenant::query()->findOrFail((int) $event['tenant_id']);
        $this->tenant->activate($tenant);

        try {
            $eventHash = hash('sha256', $event['connector_id'].'|'.$event['site_id'].'|'.$event['event_id']);
            try {
                $record = SyncWebhookEvent::query()->create([
                    'site_id' => $event['site_id'],
                    'connector_id' => $event['connector_id'],
                    'event_hash' => $eventHash,
                    'event_id' => $event['event_id'],
                    'event_type' => $event['event_type'],
                    'resource' => $event['resource'],
                    'remote_id' => $event['remote_id'],
                    'action' => $event['action'],
                    'payload_hash' => hash('sha256', json_encode($event['payload'], JSON_THROW_ON_ERROR)),
                    'payload' => $event['payload'],
                    'occurred_at' => $event['occurred_at'],
                    'verified_at' => now(),
                    'state' => 'verified',
                ]);
            } catch (UniqueConstraintViolationException) {
                return response()->json(['status' => 'duplicate']);
            }

            try {
                $run = $this->runtime->startWebhook((int) $event['tenant_id'], $event, $record->id);
            } catch (RuntimeException) {
                $record->forceFill([
                    'state' => 'deferred',
                    'last_error' => 'Sync admission deferred; fallback reconciliation remains authoritative.',
                    'processed_at' => now(),
                ])->save();

                return response()->json(['status' => 'deferred'], 202);
            }

            $record->forceFill(['state' => 'queued', 'sync_run_id' => $run->id, 'processed_at' => now()])->save();

            return response()->json(['status' => 'accepted', 'sync_run_id' => $run->id], 202);
        } finally {
            $this->tenant->forget();
        }
    }
}
