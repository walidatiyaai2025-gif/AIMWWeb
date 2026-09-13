<?php

namespace App\Jobs;

use Closure;
use InvalidArgumentException;

/**
 * Scoped Laravel adaptation of the canonical BackgroundExecutionIdentity.
 *
 * Canonical operation: AIMW-AUTO-7E70C7119A.
 *
 * The identity deliberately carries only the owning Laravel user primary key.
 * It never authenticates that user and never carries roles, permissions or
 * administrator state. AppServiceProvider binds this service as scoped so a
 * long-running queue worker cannot leak an owner into the next job scope.
 */
final class BackgroundExecutionIdentity
{
    public const OPERATION_ID = 'AIMW-AUTO-7E70C7119A';

    private ?int $ownerUserId = null;

    public function tryGetOwnerUserId(): ?int
    {
        return $this->ownerUserId;
    }

    /**
     * Push an owner for the current execution scope and return an idempotent
     * lease that restores the previous owner when disposed.
     */
    public function push(int $ownerUserId): BackgroundExecutionIdentityLease
    {
        if ($ownerUserId < 1) {
            throw new InvalidArgumentException('Background execution owner user ID is required.');
        }

        $previous = $this->ownerUserId;
        $this->ownerUserId = $ownerUserId;

        return new BackgroundExecutionIdentityLease(function () use ($previous): void {
            $this->ownerUserId = $previous;
        });
    }
}

/**
 * Disposable lease for BackgroundExecutionIdentity::push().
 *
 * Explicit dispose mirrors the source IDisposable contract. The destructor is
 * a fail-safe for exceptional PHP control flow; dispose remains idempotent.
 */
final class BackgroundExecutionIdentityLease
{
    private bool $disposed = false;

    public function __construct(private ?Closure $restore) {}

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $restore = $this->restore;
        $this->restore = null;
        $restore?->__invoke();
    }

    public function __destruct()
    {
        $this->dispose();
    }
}
