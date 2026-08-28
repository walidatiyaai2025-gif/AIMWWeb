<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Operations\OperationsControlPlaneService;
use App\Sites\SiteOperationHistoryService;
use Illuminate\Http\JsonResponse;

final class RouteApiAdapterController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly OperationsControlPlaneService $operations,
        private readonly SiteOperationHistoryService $siteOperations,
    ) {}

    public function reportExports(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('reports.view');

        return response()->json([
            'data' => $this->operations->operations(['type' => 'report.']),
        ]);
    }

    public function siteOperations(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('execution.view');

        return response()->json([
            'data' => $this->siteOperations->getAll(),
        ]);
    }
}
