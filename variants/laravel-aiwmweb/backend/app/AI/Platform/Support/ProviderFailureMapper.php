<?php

namespace App\AI\Platform\Support;

use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use Illuminate\Http\Client\Response;

final class ProviderFailureMapper
{
    public function throwForResponse(Response $response): never
    {
        $status = $response->status();
        $retryAfter = is_numeric($response->header('Retry-After'))
            ? (int) $response->header('Retry-After')
            : null;

        [$kind, $message, $retryable] = match (true) {
            in_array($status, [401, 403], true) => [
                AiFailureKind::InvalidCredentials,
                'AI provider rejected the configured credential.',
                false,
            ],
            $status === 404 => [
                AiFailureKind::ModelUnavailable,
                'AI provider model or endpoint is unavailable.',
                false,
            ],
            $status === 408 => [
                AiFailureKind::Timeout,
                'AI provider request timed out.',
                true,
            ],
            $status === 429 => [
                AiFailureKind::RateLimit,
                'AI provider rate limit was reached.',
                true,
            ],
            $status >= 500 => [
                AiFailureKind::ProviderUnavailable,
                'AI provider is temporarily unavailable.',
                true,
            ],
            default => [
                AiFailureKind::ProviderUnavailable,
                "AI provider request failed with HTTP {$status}.",
                false,
            ],
        };

        throw new AiPlatformException($kind, $message, $retryable, $status, $retryAfter);
    }
}
