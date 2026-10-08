<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Email\Services\CanonicalEmailScheduleCreateService;
use App\Email\Services\EmailScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CanonicalEmailScheduleController extends Controller
{
    public const CREATE_OPERATION_ID = 'AIMW-BILL-7101AC9489';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CanonicalEmailScheduleCreateService $creator,
        private readonly EmailScheduleService $schedules,
    ) {}

    public function index(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->schedules->all()]);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');
        $allowed = ['scope','site_id','frequency','time_of_day','weekday','month_day','timezone_id','culture','retry_count','retry_delay_minutes','enabled'];
        $unknown = array_values(array_diff(array_keys($request->all()), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['request' => 'Unsupported fields: '.implode(', ', $unknown)]);
        }
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 120) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'A valid Idempotency-Key header is required.']);
        }
        $data = $request->validate([
            'scope' => ['required', Rule::in(['Account','Site'])],
            'site_id' => ['nullable','integer','min:1','required_if:scope,Site'],
            'frequency' => ['required', Rule::in(['Hourly','Daily','Weekly','Monthly'])],
            'time_of_day' => ['required','date_format:H:i'],
            'weekday' => ['nullable','integer','between:0,6','required_if:frequency,Weekly'],
            'month_day' => ['nullable','integer','between:1,31','required_if:frequency,Monthly'],
            'timezone_id' => ['required','timezone'],
            'culture' => ['required',Rule::in(['en','ar'])],
            'retry_count' => ['required','integer','between:0,10'],
            'retry_delay_minutes' => ['required','integer','between:1,1440'],
            'enabled' => ['required','boolean'],
        ]);
        $created = $this->creator->create($data, (string) $request->user()->email, $key);

        return response()->json(['operation_id' => self::CREATE_OPERATION_ID, 'data' => $created], 201);
    }
}
