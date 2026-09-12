<?php

namespace App\Automation;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

final class ExecutionCenterUserCommandService
{
    public const PAUSE_OPERATION_ID = 'AIMW-AUTO-730076001E';

    public const RESUME_OPERATION_ID = 'AIMW-AUTO-4C1DD607BB';

    public function __construct(
        private readonly ExecutionCenterService $executionCenter,
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function pause(string $jobId): bool
    {
        return $this->execute($jobId, fn (int $ownerUserId): bool => $this->executionCenter->pause($jobId, $ownerUserId));
    }

    public function resume(string $jobId): bool
    {
        return $this->execute($jobId, fn (int $ownerUserId): bool => $this->executionCenter->resume($jobId, $ownerUserId));
    }

    /** @param callable(int):bool $command */
    private function execute(string $jobId, callable $command): bool
    {
        if (trim($jobId) === '') {
            throw new InvalidArgumentException('A valid execution job is required.');
        }

        $this->authorizer->authorize('operations.manage');
        $ownerUserId = (int) $this->context->membership()->user_id;
        if ($ownerUserId < 1) {
            throw new InvalidArgumentException('Authenticated execution owner is required.');
        }

        return $command($ownerUserId);
    }
}
