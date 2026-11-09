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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SeoController extends Controller
{
    public const RUN_FULL_AUDIT_OPERATION_ID = 'AIMW-BILL-3C55B3C299';

    public function audits(string $tenant, int $site, TenantAuthorizer $auth, Request $request): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1|max:1000000',
            'per_page' => 'sometimes|integer|in:10,15,25,50,100',
        ]);
        // Preserve the existing Laravel default of 15 for legacy clients.
        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = (int) ($filters['page'] ?? 1);
        $query = SeoAudit::query()->where('site_id', $site)
            ->orderByDesc('created_at')->orderByDesc('id');
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        // Clamp before selecting rows, not after a potentially huge OFFSET.
        return response()->json($query->paginate($perPage, ['*'], 'page', min($page, $lastPage)));
    }

    public function startAudit(string $tenant, int|string $site, Request $request, TenantContext $context, TenantAuthorizer $auth): JsonResponse
    {
        abort_unless((is_int($site) && $site > 0) || (is_string($site) && ctype_digit($site) && (int) $site > 0), 404);
        $siteId = (int) $site;
        $auth->authorize('seo.manage');
        Site::query()
            ->withoutGlobalScopes()
            ->whereKey($siteId)
            ->where('tenant_id', $context->id())
            ->firstOrFail();
        $audit = SeoAudit::query()->create(['site_id' => $siteId, 'actor_user_id' => $request->user()->id, 'status' => 'queued']);
        RunSeoAuditJob::dispatch($context->id(), $audit->id);

        return response()->json($audit, 202);
    }

    public function findings(string $tenant, int $site, int $audit, TenantAuthorizer $auth): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);
        SeoAudit::query()->where('site_id', $site)->findOrFail($audit);

        return response()->json(SeoFinding::query()->where('seo_audit_id', $audit)->orderBy('severity')->get());
    }

    public function metadata(string $tenant, int $site, string $type, int $remoteId, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('tenant.view');
        abort_unless(in_array($type, ['post', 'page'], true), 422, 'Unsupported WordPress resource type.');
        $model = Site::query()->findOrFail($site);

        return response()->json($seo->inspectRemote($model, $type, $remoteId));
    }

    public function provider(string $tenant, int $site, int $content, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('tenant.view');
        Site::query()->findOrFail($site);
        $model = SyncedContent::query()->where('site_id', $site)->findOrFail($content);

        return response()->json($seo->providerState($model->seo_provider));
    }

    public function prepare(string $tenant, int $site, int $finding, Request $request, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('seo.write');
        Site::query()->findOrFail($site);
        $model = SeoFinding::query()->findOrFail($finding);
        $content = SyncedContent::query()->where('site_id', $site)->findOrFail($model->synced_content_id);
        abort_unless($content->site_id === $site, 404);
        $data = $request->validate(['changes' => 'sometimes|array']);

        return response()->json($seo->prepareRemediation($model, $request->user()->id, (array) ($data['changes'] ?? [])), 201);
    }

    public function prepareBulk(string $tenant, int $site, Request $request, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
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
                $result['failed'][] = ['finding_id' => (int) $item['finding_id'], 'error' => self::safeBulkError($exception)];
            }
        }

        return response()->json($result, $result['failed'] === [] ? 201 : 207);
    }

    public function aiProposal(string $tenant, int $site, int $finding, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
    {
        $auth->authorize('ai.use');
        Site::query()->findOrFail($site);
        $model = SeoFinding::query()->findOrFail($finding);
        SyncedContent::query()->where('site_id', $site)->findOrFail($model->synced_content_id);

        return response()->json($seo->generateAiProposal($model, $site));
    }

    public function executeBulk(string $tenant, int $site, Request $request, TenantContext $context, TenantAuthorizer $auth, ExecutionCreator $creator): JsonResponse
    {
        $auth->authorize('seo.write');
        $auth->authorize('executions.manage');
        Site::query()->findOrFail($site);
        $data = $request->validate(['approval_ids' => 'required|array|min:1|max:100', 'approval_ids.*' => 'integer']);
        $result = ['queued' => [], 'failed' => []];

        foreach (array_values(array_unique($data['approval_ids'])) as $approvalId) {
            try {
                $approval = Approval::query()->findOrFail((int) $approvalId);
                // Scope the approval's suggestion before revealing its state.
                Suggestion::query()->where('site_id', $site)->findOrFail($approval->suggestion_id);
                abort_unless($approval->status === 'APPROVED', 409, 'Every SEO mutation requires explicit approval.');
                [$execution, $created] = $creator->create($approval, $request->user()->id);
                if ($created) {
                    ExecuteApprovedSuggestionJob::dispatch($context->id(), $execution->id);
                }
                $result['queued'][] = ['approval_id' => $approval->id, 'execution_id' => $execution->id, 'created' => $created];
            } catch (Throwable $exception) {
                $result['failed'][] = ['approval_id' => (int) $approvalId, 'error' => self::safeBulkError($exception)];
            }
        }

        return response()->json($result, $result['failed'] === [] ? 202 : 207);
    }

    public function retry(string $tenant, int $site, int $execution, TenantContext $context, TenantAuthorizer $auth, SeoManagerService $seo): JsonResponse
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

    private static function safeBulkError(Throwable $exception): string
    {
        if ($exception instanceof ModelNotFoundException) {
            return 'Item not found or unavailable.';
        }

        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                403 => 'Action not authorized.',
                404 => 'Item not found or unavailable.',
                409 => 'Item not ready for this action.',
                default => 'Operation failed.',
            };
        }

        // Never return raw provider, SQL, exception or credential text in partial results.
        return 'Operation failed.';
    }
}
