<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Content\GlobalPostsTrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GlobalPostsTrashController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-AD4781C803';

    public function __construct(private readonly GlobalPostsTrashService $posts) {}

    public function index(Request $request, TenantAuthorizer $auth, string $tenant): JsonResponse
    {
        $auth->authorize('content.view');

        $data = $request->validate([
            'search' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(['all', 'draft', 'pending', 'publish', 'private', 'future', 'trash'])],
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        return response()->json($this->posts->posts($data));
    }

    public function trash(Request $request, TenantAuthorizer $auth, string $tenant): JsonResponse
    {
        $auth->authorize('content.edit');

        $callerOwned = ['tenant', 'tenant_id', 'user_id', 'actor_user_id'];
        abort_if(array_intersect(array_keys($request->all()), $callerOwned) !== [], 422, 'Global post trash does not accept caller-owned identity fields.');

        $data = $request->validate([
            'targets' => 'required|array|min:1|max:100',
            'targets.*.site_id' => 'required|integer|min:1',
            'targets.*.wordpress_id' => 'required|integer|min:1',
        ]);

        $unique = collect($data['targets'])
            ->unique(fn (array $target): string => $target['site_id'].':'.$target['wordpress_id']);

        abort_if($unique->count() !== count($data['targets']), 422, 'Global post trash targets must be unique.');

        return response()->json($this->posts->trash($data['targets']));
    }
}
