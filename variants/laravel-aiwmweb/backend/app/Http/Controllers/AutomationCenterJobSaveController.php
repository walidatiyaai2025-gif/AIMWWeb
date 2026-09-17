<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Operations\AutomationCenterJobSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AutomationCenterJobSaveController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AutomationCenterJobSaveService $service,
    ) {}

    public function store(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');
        $this->rejectUnknownFields($request, false);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid Idempotency-Key header is required.']);
        }

        $data = $request->validate($this->rules(false));
        $saved = $this->service->create($data, (int) $request->user()->getAuthIdentifier(), $idempotencyKey);

        return response()->json(['data' => $saved], 201);
    }

    public function update(Request $request, string $tenant, int $job): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');
        $this->rejectUnknownFields($request, true);
        $data = $request->validate($this->rules(true));
        $saved = $this->service->update($job, $data, (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => $saved]);
    }

    private function rules(bool $update): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'site_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(['Synchronization', 'SEO Audit'])],
            'frequency' => ['required', 'string', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],
            'interval_value' => ['required', 'integer', 'between:1,365'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'enabled' => ['required', 'boolean'],
            'retry_count' => ['required', 'integer', 'between:0,10'],
        ];
        if ($update) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }

    private function rejectUnknownFields(Request $request, bool $update): void
    {
        $allowed = ['name', 'site_id', 'type', 'frequency', 'interval_value', 'time_of_day', 'enabled', 'retry_count'];
        if ($update) {
            $allowed[] = 'expected_version';
        }
        $unknown = array_values(array_diff(array_keys($request->all()), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['request' => 'Unsupported fields: '.implode(', ', $unknown)]);
        }
    }
}
