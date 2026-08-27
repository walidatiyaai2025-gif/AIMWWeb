<?php

namespace App\Email\Exceptions;

use RuntimeException;

final class EmailTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
