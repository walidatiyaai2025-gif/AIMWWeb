<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Approval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiCenterApprovalStatusController extends Controller
{
    public function __invoke(Request $request, TenantAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize('ai.use');

        $approval = Approval::query()
            ->where('actor_user_id', $request->user()->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => $approval ? [
                'id' => (int) $approval->id,
                'status' => (string) $approval->status,
                'decided_at' => $approval->decided_at?->toISOString(),
                'updated_at' => $approval->updated_at?->toISOString(),
            ] : null,
        ]);
    }
}
