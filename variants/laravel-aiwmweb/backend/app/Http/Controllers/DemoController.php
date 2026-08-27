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
use App\Models\EvidenceReceipt;
use App\Models\Execution;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\SyncRun;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DemoController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        abort_unless(Auth::attempt($credentials), 422, 'Invalid credentials.');
        $request->session()->regenerate();

        return response()->json(['user' => $request->user()->only(['id', 'name', 'email'])]);
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

    public function pairing(int $site, PairingService $pairing, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('connector.manage');
        $model = Site::query()->findOrFail($site);

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

    public function verify(int $site, WordPressGateway $wordpress, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('connector.manage');
        $model = Site::query()->findOrFail($site);
        $health = $wordpress->health($model);
        $model->update(['connection_status' => 'verified', 'health_state' => $health['status'] ?? 'healthy', 'last_verified_at' => now()]);
        Connector::query()->where('site_id', $site)->update(['verified_at' => now()]);

        return response()->json($health);
    }

    public function sync(int $site, TenantContext $context, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('sites.manage');
        Site::query()->findOrFail($site);
        $run = SyncRun::query()->create(['site_id' => $site]);
        SyncSiteJob::dispatch($context->id(), $site, $run->id);

        return response()->json($run, 202);
    }

    public function syncStatus(int $run, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');

        return response()->json(SyncRun::query()->findOrFail($run));
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
