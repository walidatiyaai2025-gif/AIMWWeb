<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\EvidenceReceipt;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EvidenceReceiptReadController extends Controller
{
    public function index(Request $request, TenantAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize('execution.view');

        $filters = $request->validate([
            'site_id' => ['sometimes', 'integer', 'min:1'],
            'verified' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = EvidenceReceipt::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['site_id'])) {
            Site::query()->findOrFail((int) $filters['site_id']);
            $query->where('site_id', (int) $filters['site_id']);
        }

        if (array_key_exists('verified', $filters)) {
            $query->where('verified', (bool) $filters['verified']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('operation_id', 'like', '%'.$search.'%')
                    ->orWhere('request_id', 'like', '%'.$search.'%')
                    ->orWhere('correlation_id', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $builder->orWhere('execution_id', (int) $search)
                        ->orWhere('site_id', (int) $search);
                }
            });
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $receipts = $query->paginate($perPage, ['*'], 'page', $page);
        // Filtering or deletion can leave a bookmarked page past the real end.
        // Return the last valid page rather than presenting a false empty list.
        if ($page > $receipts->lastPage()) {
            $receipts = $query->paginate($perPage, ['*'], 'page', $receipts->lastPage());
        }
        $receipts->setCollection($receipts->getCollection()->map(
            static fn (EvidenceReceipt $receipt): array => [
                'id' => (int) $receipt->id,
                'site_id' => (int) $receipt->site_id,
                'execution_id' => (int) $receipt->execution_id,
                'actor_user_id' => (int) $receipt->actor_user_id,
                'operation_id' => (string) $receipt->operation_id,
                'request_id' => (string) $receipt->request_id,
                'correlation_id' => (string) $receipt->correlation_id,
                'verified' => (bool) $receipt->verified,
                'failure_present' => $receipt->failure !== null,
                'created_at' => $receipt->created_at?->toIso8601String(),
                'updated_at' => $receipt->updated_at?->toIso8601String(),
            ],
        ));

        return response()->json($receipts);
    }
}
