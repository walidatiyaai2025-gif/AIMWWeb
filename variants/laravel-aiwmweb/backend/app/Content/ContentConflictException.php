<?php

namespace App\Content;

use RuntimeException;

final class ContentConflictException extends RuntimeException
{
    public function __construct(public readonly int $conflictId)
    {
        parent::__construct('Remote content changed after the expected version; mutation was not applied.', 409);
    }
}
