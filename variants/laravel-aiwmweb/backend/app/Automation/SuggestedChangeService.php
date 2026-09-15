<?php

namespace App\Automation;

use App\Authorization\TenantAuthorizer;
use App\Models\SuggestedChange;
use InvalidArgumentException;

final class SuggestedChangeService
{
    public const OPERATION_ID = 'AIMW-AUTO-1584B94390';

    private const EXECUTION_STATUSES = [
        'NotStarted',
        'Executing',
        'Executed',
        'Failed',
        'RolledBack',
    ];

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function SetExecutionStatusAsync(string $changeId, string $status): SuggestedChange
    {
        $this->authorizer->authorize('operations.manage');

        $change = SuggestedChange::query()
            ->where('change_id', $changeId)
            ->firstOrFail();

        if (! in_array($status, self::EXECUTION_STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported execution status: {$status}");
        }

        if ($status !== 'NotStarted') {
            $change->execution_status = $status;
            $change->save();
        }

        return $change->refresh();
    }
}
