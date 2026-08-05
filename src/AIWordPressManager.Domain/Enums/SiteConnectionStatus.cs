namespace AIWordPressManager.Domain.Enums;

/// <summary>
/// Represents the last known WordPress connection state for a configured site.
/// NotTested and Unknown intentionally share the same persisted value to remain
/// compatible with older UI and database records.
/// </summary>
public enum SiteConnectionStatus
{
    NotTested = 0,
    Unknown = NotTested,
    Connected = 1,
    AuthenticationFailed = 2,
    Unreachable = 3,
    LimitedPermissions = 4,
    Disabled = 5
}
