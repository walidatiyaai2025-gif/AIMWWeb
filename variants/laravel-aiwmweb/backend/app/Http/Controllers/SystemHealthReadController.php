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
            // Non-secret runtime evidence; the tenant/diagnostics permission gates above are mandatory.
            'profile' => (string) ($report['profile'] ?? 'standard'),
            'hosting_preflight' => (string) ($report['hosting_preflight'] ?? 'not_applicable'),
            'runtime_modes' => [
                'queue' => in_array(config('queue.default'), ['database', 'redis', 'sync', 'sqs'], true) ? config('queue.default') : 'other',
                'cache' => in_array(config('cache.default'), ['database', 'redis', 'file', 'array'], true) ? config('cache.default') : 'other',
                'cron' => config('cpanel.enabled') ? (config('cpanel.cron_enabled') ? 'expected' : 'disabled') : 'not_applicable',
            ],
            'checks' => (array) ($report['checks'] ?? []),
        ]);
    }
}
