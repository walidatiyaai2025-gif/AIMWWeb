<?php

namespace App\Http\Controllers;

use App\Sites\SiteOperationsMaintenanceSnapshotResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteOperationsMaintenanceRefreshController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-C5BC29CF27';

    public function __construct(
        private readonly SiteOperationsMaintenanceSnapshotResponder $snapshot,
    ) {}

    public function __invoke(Request $request, string $tenant): JsonResponse
    {
        return $this->snapshot->respond($request, $tenant, self::OPERATION_ID);
    }
}
