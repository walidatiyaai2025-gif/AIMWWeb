<?php

namespace App\Sync;

use RuntimeException;

final class SyncCancellationRequested extends RuntimeException
{
    public function __construct(public readonly int $syncRunId)
    {
        parent::__construct("Synchronization {$syncRunId} has a pending cancellation request.");
    }
}
