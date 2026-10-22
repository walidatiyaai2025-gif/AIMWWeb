<?php

namespace App\AI\Platform\Enums;

enum ProviderReadiness: string
{
    case Ready = 'READY';
    case NotConfigured = 'NOT_CONFIGURED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Unreachable = 'UNREACHABLE';
    case RateLimited = 'RATE_LIMITED';
    case Disabled = 'DISABLED';
    case ModelUnavailable = 'MODEL_UNAVAILABLE';
}
