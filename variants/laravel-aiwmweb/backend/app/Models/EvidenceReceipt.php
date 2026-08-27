<?php

namespace App\Models;

use LogicException;

class EvidenceReceipt extends DomainModel
{
    protected function casts(): array
    {
        return ['before_state' => 'array', 'proposed_state' => 'array', 'actual_after_state' => 'array', 'verified' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Evidence receipts are immutable.'));
    }
}
