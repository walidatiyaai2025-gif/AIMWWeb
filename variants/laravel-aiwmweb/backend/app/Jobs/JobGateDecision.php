<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;

final readonly class JobGateDecision
{
    public function __construct(
        public bool $canRun,
        public ?CarbonImmutable $resumeAtUtc = null,
        public ?string $message = null,
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }
}
