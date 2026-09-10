<?php

namespace App\Http\Controllers;

use App\Sites\SiteOperationsMaintenanceSnapshotResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteOperationsMaintenanceReloadController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-CAAC427FC0';

    public function __construct(
        private readonly SiteOperationsMaintenanceSnapshotResponder $snapshot,
    ) {}

    public function __invoke(Request $request, string $tenant): JsonResponse
    {
        return $this->snapshot->respond($request, $tenant, self::OPERATION_ID);
    }
}
