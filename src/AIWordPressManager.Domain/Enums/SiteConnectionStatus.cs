namespace AIWordPressManager.Domain.Enums;

public enum SiteConnectionStatus
{
    Unknown = 0,
    Connected = 1,
    AuthenticationFailed = 2,
    Unreachable = 3,
    LimitedPermissions = 4,
    Disabled = 5
}
