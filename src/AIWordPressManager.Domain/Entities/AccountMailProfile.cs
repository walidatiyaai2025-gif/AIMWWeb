using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class AccountMailProfile : Entity
{
    private AccountMailProfile() { }

    public AccountMailProfile(Guid ownerUserId, DateTime utcNow)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        OwnerUserId = ownerUserId;
        Port = 587;
        EnableSsl = true;
        IsEnabled = false;
        MarkUpdated(utcNow);
    }

    public Guid OwnerUserId { get; private set; }
    public string Host { get; private set; } = string.Empty;
    public int Port { get; private set; } = 587;
    public string UserName { get; private set; } = string.Empty;
    public string? ProtectedPassword { get; private set; }
    public string FromAddress { get; private set; } = string.Empty;
    public string FromName { get; private set; } = string.Empty;
    public string ReplyToAddress { get; private set; } = string.Empty;
    public bool EnableSsl { get; private set; } = true;
    public bool IsEnabled { get; private set; }

    public void Configure(string host, int port, string? userName, string fromAddress, string? fromName, string? replyToAddress, bool enableSsl, bool enabled, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(host);
        ArgumentException.ThrowIfNullOrWhiteSpace(fromAddress);
        if (port is < 1 or > 65535) throw new ArgumentOutOfRangeException(nameof(port), "SMTP port must be between 1 and 65535.");

        Host = host.Trim();
        UserName = userName?.Trim() ?? string.Empty;
        FromAddress = fromAddress.Trim();
        FromName = fromName?.Trim() ?? string.Empty;
        ReplyToAddress = replyToAddress?.Trim() ?? string.Empty;
        Port = port;
        EnableSsl = enableSsl;
        IsEnabled = enabled;
        MarkUpdated(utcNow);
    }

    public void SetProtectedPassword(string? protectedPassword, DateTime utcNow)
    {
        ProtectedPassword = string.IsNullOrWhiteSpace(protectedPassword) ? null : protectedPassword.Trim();
        MarkUpdated(utcNow);
    }

    public void ClearProtectedPassword(DateTime utcNow)
    {
        ProtectedPassword = null;
        MarkUpdated(utcNow);
    }
}
