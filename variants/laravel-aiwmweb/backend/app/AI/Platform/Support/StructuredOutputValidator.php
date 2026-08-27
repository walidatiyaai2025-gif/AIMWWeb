<?php

namespace App\AI\Platform\Support;

use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;

final class StructuredOutputValidator
{
    public function decodeAndValidate(string $content, array $schema): array
    {
        try {
            $value = json_decode(trim($content), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiPlatformException(
                AiFailureKind::InvalidOutput,
                'AI provider returned malformed JSON for a structured workflow.',
                false,
                422,
            );
        }

        if (! is_array($value)) {
            throw new AiPlatformException(
                AiFailureKind::InvalidOutput,
                'AI provider returned a non-object structured response.',
                false,
                422,
            );
        }

        $this->validateValue($value, $schema, '$');

        return $value;
    }

    private function validateValue(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'] ?? null;
        if ($type !== null && ! $this->matchesType($value, (string) $type)) {
            $this->invalid("{$path} must be of type {$type}.");
        }

        if (isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
            $this->invalid("{$path} contains a value outside the allowed enum.");
        }

        if ($type === 'object') {
            if (! is_array($value) || array_is_list($value)) {
                $this->invalid("{$path} must be an object.");
            }

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists((string) $required, $value)) {
                    $this->invalid("{$path}.{$required} is required.");
                }
            }

            $properties = (array) ($schema['properties'] ?? []);
            foreach ($value as $key => $child) {
                if (isset($properties[$key]) && is_array($properties[$key])) {
                    $this->validateValue($child, $properties[$key], "{$path}.{$key}");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $this->invalid("{$path}.{$key} is not allowed.");
                }
            }
        }

        if ($type === 'array') {
            if (! is_array($value) || ! array_is_list($value)) {
                $this->invalid("{$path} must be an array.");
            }

            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                foreach ($value as $index => $child) {
                    $this->validateValue($child, $itemSchema, "{$path}[{$index}]");
                }
            }
        }

        if ($type === 'string' && is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $this->invalid("{$path} is shorter than the allowed minimum.");
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $this->invalid("{$path} is longer than the allowed maximum.");
            }
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ! array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private function invalid(string $message): never
    {
        throw new AiPlatformException(AiFailureKind::InvalidOutput, $message, false, 422);
    }
}
