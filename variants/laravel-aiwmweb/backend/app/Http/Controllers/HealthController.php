<?php

namespace App\Http\Controllers;

use App\Services\RuntimeHealthService;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function live(RuntimeHealthService $health): JsonResponse
    {
        return response()->json($health->live());
    }

    public function ready(RuntimeHealthService $health): JsonResponse
    {
        $report = $health->ready();

        return response()->json($report, $report['status'] === 'ready' ? 200 : 503);
    }
}
