<?php

namespace App\AI\Platform\Exceptions;

use App\AI\Platform\Enums\AiFailureKind;
use RuntimeException;

final class AiPlatformException extends RuntimeException
{
    public function __construct(
        public readonly AiFailureKind $kind,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }
}
