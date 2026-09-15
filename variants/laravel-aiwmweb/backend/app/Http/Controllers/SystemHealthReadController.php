<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Services\RuntimeHealthService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class SystemHealthReadController extends Controller
{
    public function __invoke(
        string $tenant,
        RuntimeHealthService $health,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): JsonResponse {
        if (! hash_equals((string) $context->tenant()->slug, $tenant)) {
            abort(404);
        }

        $authorizer->authorize('tenant.view');
        $authorizer->authorize('diagnostics.view');

        $report = $health->ready();

        return response()->json([
            'tenant' => (string) $context->tenant()->slug,
            'status' => (string) ($report['status'] ?? 'not_ready'),
            'checks' => (array) ($report['checks'] ?? []),
        ]);
    }
}
