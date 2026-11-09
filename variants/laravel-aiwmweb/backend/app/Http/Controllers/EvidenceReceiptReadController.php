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
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
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
            // A user's identifier search is literal: raw % and _ must not
            // match unrelated immutable evidence or inflate its record count.
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $pattern = '%'.$needle.'%';
            $query->where(function ($builder) use ($search, $pattern): void {
                $builder->whereRaw("operation_id LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("request_id LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("correlation_id LIKE ? ESCAPE '!'", [$pattern]);
                if (ctype_digit($search)) {
                    $builder->orWhere('execution_id', (int) $search)
                        ->orWhere('site_id', (int) $search);
                }
            });
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        // Count within the same tenant and filters before accessing a potentially
        // enormous OFFSET. A stale bookmark lands on the last real page.
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $receipts = $query->paginate($perPage, ['*'], 'page', min($page, $lastPage));
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
