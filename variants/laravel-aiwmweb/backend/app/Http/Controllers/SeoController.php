<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Execution\ExecutionCreator;
use App\Jobs\ExecuteApprovedSuggestionJob;
use App\Jobs\RunSeoAuditJob;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Services\SeoManagerService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class SeoController extends Controller
{
    public function audits(int $site, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);

        return response()->json(SeoAudit::query()->where('site_id', $site)->latest()->paginate());
    }

    public function startAudit(int $site, Request $request, TenantContext $context, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('seo.manage');
        Site::query()->findOrFail($site);
        $audit = SeoAudit::query()->create(['site_id' => $site, 'actor_user_id' => $request->user()->id]);
        RunSeoAuditJob::dispatch($context->id(), $audit->id);

        return response()->json($audit, 202);
    }

    public function findings(int $site, int $audit, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);
        SeoAudit::query()->where('site_id', $site)->findOrFail($audit);

        return response()->json(SeoFinding::query()->where('seo_audit_id', $audit)->orderBy('severity')->get());
    }

    public function metadata(int $site, string $type, int $remoteId, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('tenant.view');
        abort_unless(in_array($type, ['post', 'page'], true), 422, 'Unsupported WordPress resource type.');
        $model = Site::query()->findOrFail($site);

        return response()->json($seo->inspectRemote($model, $type, $remoteId));
    }

    public function provider(int $site, int $content, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);
        $model = SyncedContent::query()->where('site_id', $site)->findOrFail($content);

        return response()->json($seo->providerState($model->seo_provider));
    }

    public function prepare(int $site, int $finding, Request $request, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('seo.write');
        Site::query()->findOrFail($site);
        $model = SeoFinding::query()->findOrFail($finding);
        $content = SyncedContent::query()->where('site_id', $site)->findOrFail($model->synced_content_id);
        abort_unless($content->site_id === $site, 404);
        $data = $request->validate(['changes' => 'sometimes|array']);

        return response()->json($seo->prepareRemediation($model, $request->user()->id, (array) ($data['changes'] ?? [])), 201);
    }

    public function prepareBulk(int $site, Request $request, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('seo.write');
        Site::query()->findOrFail($site);
        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.finding_id' => 'required|integer',
            'items.*.changes' => 'sometimes|array',
        ]);
        $result = ['prepared' => [], 'failed' => []];
        foreach ($data['items'] as $item) {
            try {
                $finding = SeoFinding::query()->findOrFail((int) $item['finding_id']);
                SyncedContent::query()->where('site_id', $site)->findOrFail($finding->synced_content_id);
                $prepared = $seo->prepareRemediation($finding, $request->user()->id, (array) ($item['changes'] ?? []));
                $result['prepared'][] = [
                    'finding_id' => $finding->id,
                    'suggestion_id' => $prepared['suggestion']->id,
                    'approval_id' => $prepared['approval']->id,
                    'status' => 'pending_approval',
                ];
            } catch (Throwable $exception) {
                $result['failed'][] = ['finding_id' => (int) $item['finding_id'], 'error' => $exception->getMessage()];
            }
        }

        return response()->json($result, $result['failed'] === [] ? 201 : 207);
    }

    public function aiProposal(int $site, int $finding, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('ai.use');
        Site::query()->findOrFail($site);
        $model = SeoFinding::query()->findOrFail($finding);
        SyncedContent::query()->where('site_id', $site)->findOrFail($model->synced_content_id);

        return response()->json($seo->generateAiProposal($model, $site));
    }

    public function executeBulk(int $site, Request $request, TenantContext $context, TenantAuthorizer $auth, ExecutionCreator $creator): JsonResponse
    {
        $auth->authorize('seo.write');
        $auth->authorize('executions.manage');
        Site::query()->findOrFail($site);
        $data = $request->validate(['approval_ids' => 'required|array|min:1|max:100', 'approval_ids.*' => 'integer']);
        $result = ['queued' => [], 'failed' => []];

        foreach (array_values(array_unique($data['approval_ids'])) as $approvalId) {
            try {
                $approval = Approval::query()->findOrFail((int) $approvalId);
                abort_unless($approval->status === 'APPROVED', 409, 'Every SEO mutation requires explicit approval.');
                $suggestion = Suggestion::query()->where('site_id', $site)->findOrFail($approval->suggestion_id);
                abort_unless($suggestion->site_id === $site, 404);
                [$execution, $created] = $creator->create($approval, $request->user()->id);
                if ($created) {
                    ExecuteApprovedSuggestionJob::dispatch($context->id(), $execution->id);
                }
                $result['queued'][] = ['approval_id' => $approval->id, 'execution_id' => $execution->id, 'created' => $created];
            } catch (Throwable $exception) {
                $result['failed'][] = ['approval_id' => (int) $approvalId, 'error' => $exception->getMessage()];
            }
        }

        return response()->json($result, $result['failed'] === [] ? 202 : 207);
    }

    public function retry(int $site, int $execution, TenantContext $context, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('seo.write');
        $auth->authorize('executions.manage');
        Site::query()->findOrFail($site);
        $model = Execution::query()->where('site_id', $site)->findOrFail($execution);
        abort_unless($seo->retryable($model), 409, 'Execution is not retryable.');
        $model->update(['status' => 'queued', 'failure' => null, 'completed_at' => null]);
        ExecuteApprovedSuggestionJob::dispatch($context->id(), $model->id);

        return response()->json($model->fresh(), 202);
    }
}
