<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Connector\PairingService;
use App\Connector\WordPressGateway;
use App\Execution\ExecutionCreator;
use App\Jobs\ExecuteApprovedSuggestionJob;
use App\Jobs\GenerateSuggestionJob;
use App\Jobs\RunSeoAuditJob;
use App\Jobs\SyncSiteJob;
use App\Models\AiProviderConfig;
use App\Models\Approval;
use App\Models\Connector;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\IdempotencyKey;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\SyncRun;
use App\Sync\SyncDiagnosticFormatter;
use App\Sync\SyncRuntimeService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class DemoController extends Controller
{
    public const SAVE_AND_TEST_OPERATION_ID = 'AIMW-BILL-C37235F06A';

    public const SITE_DETAILS_SYNC_OPERATION_ID = 'AIMW-BILL-94755B17E4';

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => 'nullable|string|max:255|required_without:email',
            'email' => 'nullable|string|max:255|required_without:login',
            'password' => 'required|string',
        ]);

        $identifier = trim((string) ($data['login'] ?? $data['email'] ?? ''));
        $credentials = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? ['email' => $identifier, 'password' => $data['password']]
            : ['normalized_username' => strtoupper($identifier), 'password' => $data['password']];

        abort_unless(Auth::attempt($credentials), 422, 'Invalid credentials.');
        $request->session()->regenerate();

        return response()->json(['user' => $request->user()->only(['id', 'name', 'username', 'email'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function sites(TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(Site::query()->latest()->get());
    }

    public function createSite(Request $request, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('sites.manage');
        $data = $request->validate(['name' => 'required|string|max:255', 'url' => 'required|url|max:2048']);
        $data['url'] = rtrim($data['url'], '/');

        return response()->json(Site::query()->create($data), 201);
    }

    public function showSite(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(Site::query()->findOrFail($site));
    }

    public function updateSite(Request $request, int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('sites.manage');
        $model = Site::query()->findOrFail($site);
        $model->update($request->validate(['name' => 'sometimes|required|string|max:255', 'url' => 'sometimes|required|url|max:2048', 'status' => 'sometimes|in:active,disabled']));

        return response()->json($model);
    }

    public function deleteSite(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('sites.manage');
        $model = Site::query()->findOrFail($site);
        abort_if(Execution::query()->where('site_id', $site)->whereIn('status', ['queued', 'running'])->exists(), 409, 'Active execution prevents deletion.');
        $model->delete();

        return response()->json([], 204);
    }

    public function pairing(
        string $tenant,
        int|string $site,
        PairingService $pairing,
        TenantAuthorizer $auth,
        TenantContext $context,
    ): JsonResponse {
        $auth->authorize('connector.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);

        $siteId = (int) $site;
        abort_if($siteId < 1, 404);
        $model = Site::query()->findOrFail($siteId);

        return response()->json(['pairing_token' => $pairing->create($model), 'expires_in' => 600]);
    }

    public function completePairing(Request $request, PairingService $pairing): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string', 'identity' => 'required|uuid', 'protocol_version' => 'required|string', 'capabilities' => 'required|array']);

        return response()->json($pairing->complete($data['token'], $data['identity'], $data['capabilities'], $data['protocol_version']), 201);
    }

    public function connector(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(Connector::query()->where('site_id', $site)->firstOrFail());
    }

    public function scopes(Request $request, int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('connector.manage');
        $connector = Connector::query()->where('site_id', $site)->firstOrFail();
        $scopes = $request->validate(['scopes' => 'required|array', 'scopes.*' => 'string'])['scopes'];
        abort_if(array_diff($scopes, $connector->capabilities), 422, 'Scope not supported by connector.');
        $connector->update(['enabled_scopes' => array_values(array_unique($scopes))]);

        return response()->json($connector);
    }

    public function revoke(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('connector.manage');
        app(WordPressGateway::class)->disconnect(Site::query()->findOrFail($site));
        $connector = Connector::query()->where('site_id', $site)->firstOrFail();
        $connector->update(['revoked_at' => now(), 'enabled_scopes' => []]);
        Site::query()->findOrFail($site)->update(['connection_status' => 'revoked']);

        return response()->json(['revoked' => true]);
    }

    public function rotate(int $site, TenantAuthorizer $auth, WordPressGateway $wordpress): JsonResponse
    {
        $auth->authorize('connector.manage');
        $model = Site::query()->findOrFail($site);
        $connector = Connector::query()->where('site_id', $site)->firstOrFail();
        $secret = Str::random(64);
        $wordpress->rotateSecret($model, $secret);
        $connector->update(['encrypted_secret' => $secret]);

        return response()->json(['rotated' => true]);
    }

    public function verify(
        string $tenant,
        int|string $site,
        WordPressGateway $wordpress,
        TenantContext $context,
        TenantAuthorizer $auth,
    ): JsonResponse {
        $auth->authorize('connector.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);
        $site = (int) $site;
        abort_if($site < 1, 404);

        $model = Site::query()->findOrFail($site);
        $health = $wordpress->health($model);
        $model->update(['connection_status' => 'verified', 'health_state' => $health['status'] ?? 'healthy', 'last_verified_at' => now()]);
        Connector::query()->where('site_id', $site)->update(['verified_at' => now()]);

        return response()->json($health);
    }

    public function sync(string $tenant, int|string $site, Request $request, TenantContext $context, TenantAuthorizer $auth, SyncRuntimeService $runtime): JsonResponse
    {
        $auth->authorize('sites.manage');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);
        $siteId = (int) $site;
        abort_if($siteId < 1, 404);
        Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->id())
            ->whereKey($siteId)
            ->firstOrFail();

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if(strlen($idempotencyKey) > 128, 422, 'Idempotency-Key must not exceed 128 characters.');
        $requestHash = hash('sha256', json_encode([
            'tenant_id' => $context->id(),
            'site_id' => $siteId,
            'operation' => 'site.sync',
        ], JSON_THROW_ON_ERROR));

        // The connected, verified AIMW Connector supports signed, paged resource
        // reads. Use the canonical projector for content workspaces; the older
        // SEO-only SyncedContent job remains for application-password sites.
        $connector = Connector::query()->where('site_id', $siteId)->first();
        $canonical = $connector !== null && $connector->revoked_at === null
            && $connector->verified_at !== null
            && in_array('content.read', (array) $connector->enabled_scopes, true);

        try {
            $result = DB::transaction(function () use ($siteId, $idempotencyKey, $requestHash, $canonical, $runtime, $context, $request): array {
                // Serialize sync starts for this site before checking active runs. Uniqueness
                // is per run so a failed run cannot suppress dispatch of its explicit retry.
                Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();
                $receipt = null;
                if ($idempotencyKey !== '') {
                    $receipt = IdempotencyKey::query()
                        ->where('key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($receipt) {
                        abort_unless(
                            $receipt->operation === 'site.sync' && hash_equals((string) $receipt->request_hash, $requestHash),
                            409,
                            'Idempotency key was already used for a different request.',
                        );
                        $acceptedRunId = (int) (($receipt->response ?? [])['run_id'] ?? 0);
                        if ($receipt->completed_at && $acceptedRunId > 0) {
                            return [
                                'run' => SyncRun::query()->findOrFail($acceptedRunId),
                                'created' => false,
                                'replay' => true,
                            ];
                        }
                    }
                }

                $run = SyncRun::query()
                    ->where('site_id', $siteId)
                    ->whereIn($canonical ? 'state' : 'status', ['queued', 'running'])
                    ->whereNull('completed_at')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();
                $created = false;

                if (! $run) {
                    if ($canonical) {
                        $hasCanonicalRows = ContentItem::query()->where('site_id', $siteId)->exists()
                            || MediaItem::query()->where('site_id', $siteId)->exists();
                        $run = $runtime->start(
                            $context->id(),
                            $siteId,
                            ! $hasCanonicalRows,
                            SyncRuntimeService::RESOURCES,
                            'site-sync-button',
                            [],
                            (int) $request->user()->getAuthIdentifier(),
                        );
                    } else {
                        $run = SyncRun::query()->create(['site_id' => $siteId]);
                    }
                    $created = true;
                }

                if ($idempotencyKey !== '') {
                    $receipt ??= IdempotencyKey::query()->create([
                        'key' => $idempotencyKey,
                        'operation' => 'site.sync',
                        'request_hash' => $requestHash,
                    ]);
                    $receipt->response = ['run_id' => (int) $run->getKey()];
                    $receipt->completed_at = now();
                    $receipt->save();
                }

                return ['run' => $run, 'created' => $created, 'replay' => false];
            }, 3);

            if ($result['created'] && ! $canonical) {
                try {
                    SyncSiteJob::dispatch($context->id(), $siteId, $result['run']->id);
                } catch (Throwable $exception) {
                    SyncRun::query()->whereKey($result['run']->id)->update([
                        'status' => 'failed',
                        'failure' => 'Queue dispatch failed before synchronization could start.',
                        'completed_at' => now(),
                    ]);
                    throw $exception;
                }
            }

            $authoritative = SyncRun::query()->findOrFail($result['run']->id);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof ModelNotFoundException) {
                throw $exception;
            }

            // The manager has passed tenant and sites.manage authorization.
            // Expose the redacted exception, never a stack trace or raw credentials.
            $diagnostic = SyncDiagnosticFormatter::exception($exception);
            $requestId = (string) $request->attributes->get('request_id', '');
            Log::error('wordpress.sync.start_failed', [
                'site_id' => $siteId,
                'request_id' => $requestId,
                'exception_type' => class_basename($exception),
                'diagnostic' => $diagnostic,
            ]);

            return response()->json([
                'code' => 'WORDPRESS_SYNC_START_FAILED',
                'message' => $diagnostic,
                'exception_type' => class_basename($exception),
                'request_id' => $requestId,
            ], 500);
        }

        return response()->json(
            $this->syncRunResource($authoritative, (bool) $result['replay'], true),
            $result['created'] ? 202 : 200,
        );
    }

    public function syncStatus(string $tenant, int|string $run, TenantAuthorizer $auth, TenantContext $context): JsonResponse
    {
        $auth->authorize('tenant.view');
        abort_unless($tenant === $context->tenant()->slug, 404);
        abort_unless(is_int($run) || ctype_digit($run), 404);
        $runId = (int) $run;
        abort_if($runId < 1, 404);
        $model = SyncRun::query()->findOrFail($runId);

        return response()->json($this->syncRunResource($model, false, $context->membership()->hasPermission('sites.manage')));
    }

    /** @return array<string, mixed> */
    private function syncRunResource(SyncRun $run, bool $replay = false, bool $showDiagnostics = false): array
    {
        $canonical = is_array($run->resources) && $run->resources !== [];
        $status = $canonical ? ($run->state === 'completed' ? 'succeeded' : (string) $run->state) : (string) $run->status;
        $processed = $canonical
            ? (int) $run->created_count + (int) $run->updated_count + (int) $run->unchanged_count + (int) $run->conflicted_count
            : (int) ($run->processed ?? 0);
        $failure = $canonical ? $run->last_error : $run->failure;

        return [
            'id' => (int) $run->getKey(),
            'site_id' => (int) $run->site_id,
            'status' => $status,
            'processed' => $processed,
            'failure' => filled($failure) || in_array($status, ['failed', 'partial'], true)
                ? ($showDiagnostics ? SyncDiagnosticFormatter::sanitize($failure) : 'Synchronization failed.')
                : null,
            'diagnostic_code' => in_array($status, ['failed', 'partial'], true) ? 'WORDPRESS_SYNC_FAILED' : null,
            'started_at' => $run->started_at?->utc()->toIso8601String(),
            'completed_at' => $run->completed_at?->utc()->toIso8601String(),
            'idempotent_replay' => $replay,
        ];
    }

    public function content(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);

        return response()->json(SyncedContent::query()->where('site_id', $site)->paginate());
    }

    public function audit(int $site, Request $request, TenantContext $context, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('seo.manage');
        Site::query()->findOrFail($site);
        $audit = SeoAudit::query()->create(['site_id' => $site, 'actor_user_id' => $request->user()->id]);
        RunSeoAuditJob::dispatch($context->id(), $audit->id);

        return response()->json($audit, 202);
    }

    public function findings(int $audit, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');
        SeoAudit::query()->findOrFail($audit);

        return response()->json(SeoFinding::query()->where('seo_audit_id', $audit)->get());
    }

    public function configureAi(Request $request, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('ai.manage');
        $data = $request->validate(['provider' => 'required|string', 'endpoint' => 'required|url', 'model' => 'required|string', 'api_key' => 'required|string|min:8']);
        $config = AiProviderConfig::query()->updateOrCreate(['provider' => $data['provider']], ['endpoint' => $data['endpoint'], 'model' => $data['model'], 'encrypted_api_key' => $data['api_key'], 'enabled' => true]);

        return response()->json($config->only(['id', 'provider', 'endpoint', 'model', 'enabled']), 201);
    }

    public function suggest(int $finding, Request $request, TenantContext $context, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('ai.use');
        $model = SeoFinding::query()->findOrFail($finding);
        $content = SyncedContent::query()->findOrFail($model->synced_content_id);
        $suggestion = Suggestion::query()->create(['site_id' => $content->site_id, 'seo_finding_id' => $model->id, 'synced_content_id' => $content->id, 'actor_user_id' => $request->user()->id, 'before_state' => $content->only(['slug', 'title', 'content', 'seo_title', 'seo_description'])]);
        GenerateSuggestionJob::dispatch($context->id(), $suggestion->id);

        return response()->json($suggestion, 202);
    }

    public function decide(Request $request, int $approval, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('approvals.manage');
        $model = Approval::query()->findOrFail($approval);
        abort_unless($model->status === 'PENDING', 409, 'Approval already decided.');
        $status = $request->validate(['status' => 'required|in:APPROVED,REJECTED'])['status'];
        $model->update(['status' => $status, 'actor_user_id' => $request->user()->id, 'decided_at' => now()]);

        return response()->json($model);
    }

    public function execute(int $approval, Request $request, TenantContext $context, TenantAuthorizer $auth, ExecutionCreator $creator): JsonResponse
    {
        $auth->authorize('executions.manage');
        $model = Approval::query()->findOrFail($approval);
        abort_unless($model->status === 'APPROVED', 409, 'Approval required.');
        [$execution, $created] = $creator->create($model, $request->user()->id);
        if ($created) {
            ExecuteApprovedSuggestionJob::dispatch($context->id(), $execution->id);
        }

        return response()->json($execution, $created ? 202 : 200);
    }

    public function cancel(int $execution, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('executions.manage');
        $model = Execution::query()->findOrFail($execution);
        abort_unless($model->status === 'queued', 409, 'Only queued execution can be cancelled.');
        $model->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json($model);
    }

    public function receipt(int $execution, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');
        Execution::query()->findOrFail($execution);

        return response()->json(EvidenceReceipt::query()->where('execution_id', $execution)->firstOrFail());
    }
}
