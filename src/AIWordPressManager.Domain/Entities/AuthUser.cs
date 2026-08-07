using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class AuthUser : Entity
{
    private const int MaximumFailedAccessAttempts = 5;
    private static readonly TimeSpan LockoutDuration = TimeSpan.FromMinutes(15);

    private AuthUser() { }

    public AuthUser(string userName, string passwordHash, DateTime utcNow, string role = "User")
    {
        SetUserName(userName, utcNow);
        SetPasswordHash(passwordHash, utcNow);
        SetRole(role, utcNow);
        IsActive = true;
    }

    public string UserName { get; private set; } = string.Empty;
    public string NormalizedUserName { get; private set; } = string.Empty;
    public string PasswordHash { get; private set; } = string.Empty;
    public string Role { get; private set; } = "User";
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

    public void SetRole(string value, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        Role = value.Trim();
        MarkUpdated(utcNow);
    }

    public void SetActive(bool isActive, DateTime utcNow)
    {
        IsActive = isActive;
        if (!isActive)
        {
            FailedAccessCount = 0;
            LockedUntilUtc = null;
        }
        MarkUpdated(utcNow);
    }

    public void Unlock(DateTime utcNow)
    {
        FailedAccessCount = 0;
        LockedUntilUtc = null;
        MarkUpdated(utcNow);
    }

    public bool IsLockedOut(DateTime utcNow) => LockedUntilUtc is { } lockedUntil && lockedUntil > utcNow;

    public void RecordSuccessfulLogin(DateTime utcNow)
    {
        FailedAccessCount = 0;
        LockedUntilUtc = null;
        LastLoginAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void RecordFailedLogin(DateTime utcNow)
    {
        if (LockedUntilUtc is { } lockedUntil && lockedUntil <= utcNow)
        {
            FailedAccessCount = 0;
            LockedUntilUtc = null;
        }

        FailedAccessCount++;
        if (FailedAccessCount >= MaximumFailedAccessAttempts)
            LockedUntilUtc = utcNow.Add(LockoutDuration);
        MarkUpdated(utcNow);
    }

    public void SetLastPage(string? path, DateTime utcNow)
    {
        if (!string.IsNullOrWhiteSpace(path) && path.StartsWith('/') && !path.StartsWith("//"))
            LastPage = path;
        MarkUpdated(utcNow);
    }
}
