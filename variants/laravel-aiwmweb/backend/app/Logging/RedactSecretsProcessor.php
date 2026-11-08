<?php

namespace App\Logging;

use App\Operations\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactSecretsProcessor implements ProcessorInterface
{
    private const REDACTED = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: (new Redactor)->redactLogMessage($record->message),
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
        // A depth limit must not expose the untouched remainder of a log
        // context. Preserve keys for structure while masking every value,
        // including any nested arrays, without descending any further.
        if ($depth >= 5) {
            return array_fill_keys(array_keys($values), self::REDACTED);
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
