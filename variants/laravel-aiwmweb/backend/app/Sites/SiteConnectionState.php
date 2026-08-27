<?php

namespace App\Sites;

final class SiteConnectionState
{
    public const CONNECTED = 'CONNECTED';

    public const DEGRADED = 'DEGRADED';

    public const DISCONNECTED = 'DISCONNECTED';

    public const AUTH_FAILED = 'AUTH_FAILED';

    public const CONNECTOR_DISABLED = 'CONNECTOR_DISABLED';

    public const CAPABILITY_DISABLED = 'CAPABILITY_DISABLED';

    public const UNSUPPORTED = 'UNSUPPORTED';

    public const TEMPORARILY_UNAVAILABLE = 'TEMPORARILY_UNAVAILABLE';

    public const ALL = [
        self::CONNECTED,
        self::DEGRADED,
        self::DISCONNECTED,
        self::AUTH_FAILED,
        self::CONNECTOR_DISABLED,
        self::CAPABILITY_DISABLED,
        self::UNSUPPORTED,
        self::TEMPORARILY_UNAVAILABLE,
    ];
}
