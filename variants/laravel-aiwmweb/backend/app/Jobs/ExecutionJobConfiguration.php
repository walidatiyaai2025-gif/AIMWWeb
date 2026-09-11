<?php

namespace App\Jobs;

use InvalidArgumentException;

/**
 * Laravel persistence contract for the canonical ExecutionJobConfiguration.
 *
 * Canonical operation: AIMW-AUTO-CC236E83A3.
 *
 * The Laravel variant adapts the source ExecutionJobs mapping onto the existing
 * tenant-owned executions table instead of creating a second execution runtime.
 */
final class ExecutionJobConfiguration
{
    public const OPERATION_ID = 'AIMW-AUTO-CC236E83A3';

    public const TABLE = 'executions';

    public const JOB_TYPE_MAX_LENGTH = 80;

    public const STATUS_MAX_LENGTH = 40;

    public const CURRENT_STEP_MAX_LENGTH = 300;

    public const ERROR_DETAILS_MAX_LENGTH = 4000;

    /**
     * Validate the bounded canonical persistence fields before an execution
     * record is intentionally constructed or transitioned through this adapter.
     */
    public function validate(
        string $jobType,
        string $status,
        string $currentStep,
        ?string $errorDetails,
        int $progressPercent,
        int $concurrencyToken,
    ): void {
        $this->requireBoundedString('job type', $jobType, self::JOB_TYPE_MAX_LENGTH);
        $this->requireBoundedString('status', $status, self::STATUS_MAX_LENGTH);
        $this->requireBoundedString('current step', $currentStep, self::CURRENT_STEP_MAX_LENGTH);

        if ($errorDetails !== null && mb_strlen($errorDetails) > self::ERROR_DETAILS_MAX_LENGTH) {
            throw new InvalidArgumentException('Execution job error details exceed the canonical persistence limit.');
        }

        if ($progressPercent < 0 || $progressPercent > 100) {
            throw new InvalidArgumentException('Execution job progress must be between 0 and 100.');
        }

        if ($concurrencyToken < 0) {
            throw new InvalidArgumentException('Execution job concurrency token cannot be negative.');
        }
    }

    private function requireBoundedString(string $field, string $value, int $maxLength): void
    {
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Execution job {$field} is required and must not exceed {$maxLength} characters.");
        }
    }
}
