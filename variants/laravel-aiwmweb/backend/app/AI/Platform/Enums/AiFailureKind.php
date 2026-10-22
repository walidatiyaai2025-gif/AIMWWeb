<?php

namespace App\AI\Platform\Enums;

enum AiFailureKind: string
{
    case Timeout = 'timeout';
    case ProviderUnavailable = 'provider_unavailable';
    case RateLimit = 'rate_limit';
    case InvalidCredentials = 'invalid_credentials';
    case InvalidOutput = 'invalid_output';
    case QuotaExceeded = 'quota_exceeded';
    case QuotaBackendUnavailable = 'quota_backend_unavailable';
    case PolicyRejection = 'policy_rejection';
    case ModelUnavailable = 'model_unavailable';
    case Unknown = 'unknown';

    public function retryable(): bool
    {
        return in_array($this, [
            self::Timeout,
            self::ProviderUnavailable,
            self::RateLimit,
        ], true);
    }
}
