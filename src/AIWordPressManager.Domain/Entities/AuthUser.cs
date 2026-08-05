using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class AuthUser : Entity
{
    private AuthUser() { }

    public AuthUser(string userName, string passwordHash, DateTime utcNow)
    {
        SetUserName(userName, utcNow);
        SetPasswordHash(passwordHash, utcNow);
        IsActive = true;
    }

    public string UserName { get; private set; } = string.Empty;
    public string NormalizedUserName { get; private set; } = string.Empty;
    public string PasswordHash { get; private set; } = string.Empty;
    public string Role { get; private set; } = "Administrator";
    public bool IsActive { get; private set; }
    public int FailedAccessCount { get; private set; }
    public DateTime? LockedUntilUtc { get; private set; }
    public DateTime? LastLoginAtUtc { get; private set; }
    public string LastPage { get; private set; } = "/";

    public void SetUserName(string value, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        UserName = value.Trim();
        NormalizedUserName = UserName.ToUpperInvariant();
        MarkUpdated(utcNow);
    }

    public void SetPasswordHash(string value, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        PasswordHash = value;
        MarkUpdated(utcNow);
    }

    public void RecordSuccessfulLogin(DateTime utcNow)
    {
        FailedAccessCount = 0;
        LockedUntilUtc = null;
        LastLoginAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void RecordFailedLogin(DateTime utcNow)
    {
        FailedAccessCount++;
        if (FailedAccessCount >= 5)
            LockedUntilUtc = utcNow.AddMinutes(15);
        MarkUpdated(utcNow);
    }

    public void SetLastPage(string? path, DateTime utcNow)
    {
        if (!string.IsNullOrWhiteSpace(path) && path.StartsWith('/') && !path.StartsWith("//"))
            LastPage = path;
        MarkUpdated(utcNow);
    }
}
