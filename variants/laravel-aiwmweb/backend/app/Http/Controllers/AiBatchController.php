<?php

namespace App\Http\Controllers;

use App\AI\Durable\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiBatchController extends Controller
{
    public function store(Request $request, BatchService $batches): JsonResponse
    {
        $data = $request->validate([
            'submission_key' => ['required', 'string', 'max:160'],
            'site_id' => ['required', 'integer', 'min:1'],
            'actions' => ['required', 'array', 'min:1', 'max:100'],
            'actions.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'actions.*.request' => ['required', 'array:workflow,system_prompt,user_prompt,prompt_key,variables,output_schema,model,temperature,max_output_tokens'],
            'actions.*.request.workflow' => ['required', 'string', 'max:100'],
            'actions.*.request.user_prompt' => ['nullable', 'string', 'max:200000'],
            'actions.*.request.system_prompt' => ['nullable', 'string', 'max:20000'],
            'actions.*.request.max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:8000'],
        ]);
        $batch = $batches->submit($data['actions'], $data['site_id'], $data['submission_key']);

        return response()->json(['data' => $batch, 'status_url' => $batch['status_url']], 202);
    }

    public function show(string $tenant, string $batch, BatchService $batches): JsonResponse
    {
        return response()->json(['data' => $batches->status($batch)]);
    }

    public function control(Request $request, string $tenant, string $batch, string $action, BatchService $batches): JsonResponse
    {
        return response()->json(['data' => $batches->control($batch, $action)]);
    }
}
