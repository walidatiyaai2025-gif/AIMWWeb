<?php

namespace App\Logging;

use Monolog\LogRecord;

final class RedactSecretsProcessor
{
    private const REDACTED = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redact(array $values, int $depth = 0): array
    {
        if ($depth >= 5) {
            return $values;
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|cookie|api[-_]?key|private[-_]?key/i', $key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value, $depth + 1);
            }
        }

        return $values;
    }
}
