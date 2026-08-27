<?php

namespace App\Billing\Enums;

enum SubscriptionState: string
{
    case TRIALING = 'TRIALING';
    case ACTIVE = 'ACTIVE';
    case PAST_DUE = 'PAST_DUE';
    case GRACE = 'GRACE';
    case SUSPENDED = 'SUSPENDED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    public function grantsEntitlements(): bool
    {
        return in_array($this, [self::TRIALING, self::ACTIVE, self::GRACE], true);
    }
}
