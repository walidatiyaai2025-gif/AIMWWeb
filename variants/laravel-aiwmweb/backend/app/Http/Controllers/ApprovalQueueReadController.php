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

        $search = trim((string) $request->query('search', ''));
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        $query = Approval::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('status', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search)
                        ->orWhere('suggestion_id', (int) $search);
                }
            });
        }

        $paginator = $query->paginate($perPage);
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
