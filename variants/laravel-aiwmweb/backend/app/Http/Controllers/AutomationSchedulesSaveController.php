<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Operations\AutomationCenterJobSaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AutomationSchedulesSaveController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-B6BE029CB8';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AutomationCenterJobSaveService $service,
    ) {}

    public function save(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');
        $this->rejectUnknownFields($request);

        $data = $request->validate([
            'job' => ['nullable', 'integer', 'min:1'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:200', 'regex:/\\S/u'],
            'site_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(['Synchronization', 'SEO Audit'])],
            'frequency' => ['required', 'string', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],
            'interval_value' => ['required', 'integer', 'between:1,365'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'enabled' => ['required', 'boolean'],
            'retry_count' => ['required', 'integer', 'between:0,10'],
        ]);

        $actorUserId = (int) $request->user()->getAuthIdentifier();
        $jobId = isset($data['job']) ? (int) $data['job'] : null;

        if ($jobId === null) {
            if (array_key_exists('expected_version', $data) && $data['expected_version'] !== null) {
                throw ValidationException::withMessages([
                    'expected_version' => 'expected_version is valid only when editing an existing schedule.',
                ]);
            }

            $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
                throw ValidationException::withMessages([
                    'Idempotency-Key' => 'A valid Idempotency-Key header is required when creating a schedule.',
                ]);
            }

            unset($data['job'], $data['expected_version']);
            $saved = $this->service->create($data, $actorUserId, $idempotencyKey);

            return response()->json([
                'operation_id' => self::OPERATION_ID,
                'mutation' => 'created',
                'data' => $saved,
            ], 201);
        }

        if (! isset($data['expected_version'])) {
            throw ValidationException::withMessages([
                'expected_version' => 'expected_version is required when editing an existing schedule.',
            ]);
        }

        unset($data['job']);
        $saved = $this->service->update($jobId, $data, $actorUserId);

        return response()->json([
            'operation_id' => self::OPERATION_ID,
            'mutation' => 'updated',
            'data' => $saved,
        ]);
    }

    private function rejectUnknownFields(Request $request): void
    {
        $allowed = [
            'job',
            'expected_version',
            'name',
            'site_id',
            'type',
            'frequency',
            'interval_value',
            'time_of_day',
            'enabled',
            'retry_count',
        ];
        $unknown = array_values(array_diff(array_keys($request->all()), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'request' => 'Unsupported fields: '.implode(', ', $unknown),
            ]);
        }
    }
}
