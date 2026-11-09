<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Approval;
use App\Models\Execution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApprovalQueueReadController extends Controller
{
    public function index(Request $request, TenantAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize('approvals.view');

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'in:10,20,25,50,100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        $query = Approval::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($search !== '') {
            // A title/status search is literal: SQL wildcards must not match
            // unrelated approval records or inflate authoritative totals.
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $pattern = '%'.$needle.'%';
            $query->where(function ($query) use ($search, $pattern): void {
                $query->whereRaw("status LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("operation_type LIKE ? ESCAPE '!'", [$pattern]);
                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search)
                        ->orWhere('suggestion_id', (int) $search);
                }
            });
        }

        // Preserve accurate server-side totals while clamping stale page URLs
        // after filters change or the last row on the last page is deleted.
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        if ($page > $paginator->lastPage()) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }
        $approvalIds = $paginator->getCollection()->pluck('id');
        $executions = Execution::query()
            ->whereIn('approval_id', $approvalIds)
            ->get()
            ->keyBy('approval_id');

        $paginator->setCollection($paginator->getCollection()->map(
            static function (Approval $approval) use ($executions): array {
                /** @var Execution|null $execution */
                $execution = $executions->get($approval->id);

                return [
                    'id' => $approval->id,
                    'status' => $approval->status,
                    'suggestion_id' => $approval->suggestion_id,
                    'site_id' => $approval->site_id,
                    'site_name' => $approval->site_name,
                    'source_operation_id' => $approval->source_operation_id,
                    'operation_type' => $approval->operation_type,
                    'title' => $approval->title,
                    'actor_label' => $approval->actor_label,
                    'risk_level' => $approval->risk_level,
                    'requested_by_user_id' => $approval->actor_user_id,
                    'execution_id' => $execution?->id,
                    'execution_status' => $execution?->status,
                    'before_state' => $approval->before_state,
                    'proposed_state' => $approval->proposed_state,
                    'decided_at' => $approval->decided_at,
                    'created_at' => $approval->created_at,
                    'updated_at' => $approval->updated_at,
                ];
            }
        ));

        return response()->json($paginator);
    }
}
