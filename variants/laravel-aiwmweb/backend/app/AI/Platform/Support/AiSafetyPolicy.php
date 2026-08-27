<?php

namespace App\AI\Platform\Support;

use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;

final class AiSafetyPolicy
{
    public const MAX_PROMPT_LENGTH = 50000;

    public function sanitizePrompt(?string $value): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > self::MAX_PROMPT_LENGTH) {
            throw new AiPlatformException(
                AiFailureKind::PolicyRejection,
                'AI input exceeds the configured safety limit.',
                false,
                422,
            );
        }

        return $this->redact($value);
    }

    public function sanitizeError(?string $value): string
    {
        $value = mb_substr(trim((string) $value), 0, 1000);

        return $this->redact($value);
    }

    public function redact(string $value): string
    {
        $patterns = [
            '/\b(sk-[A-Za-z0-9_-]{12,})\b/i',
            '/\b(AIza[A-Za-z0-9_-]{20,})\b/',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*\b/i',
            '/(api[_-]?key\s*[:=]\s*)[^\s,;]+/i',
            '/(x-api-key\s*[:=]\s*)[^\s,;]+/i',
        ];

        return (string) preg_replace($patterns, '$1[REDACTED]', $value);
    }
}
