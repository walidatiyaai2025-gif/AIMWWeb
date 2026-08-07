using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SiteMailProfile : Entity
{
    private SiteMailProfile()
    {
    }

    public SiteMailProfile(Guid siteId, Guid ownerUserId, DateTime utcNow)
    {
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));

        SiteId = siteId;
        OwnerUserId = ownerUserId;
        UseAccountProfile = true;
        Port = 587;
        EnableSsl = true;
        IsEnabled = false;
        MarkUpdated(utcNow);
    }

    public Guid SiteId { get; private set; }
    public Guid OwnerUserId { get; private set; }
    public bool UseAccountProfile { get; private set; }
    public string Host { get; private set; } = string.Empty;
    public int Port { get; private set; } = 587;
    public string UserName { get; private set; } = string.Empty;
    public string? ProtectedPassword { get; private set; }
    public string FromAddress { get; private set; } = string.Empty;
    public string FromName { get; private set; } = string.Empty;
    public string ReplyToAddress { get; private set; } = string.Empty;
    public bool EnableSsl { get; private set; } = true;
    public bool IsEnabled { get; private set; }

    public void ConfigureInheritance(bool useAccountProfile, bool enabled, DateTime utcNow)
    {
        UseAccountProfile = useAccountProfile;
        IsEnabled = enabled;
        MarkUpdated(utcNow);
    }

    public void ConfigureSmtp(
        string host,
        int port,
        string? userName,
        string fromAddress,
        string? fromName,
        string? replyToAddress,
        bool enableSsl,
        bool enabled,
        DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(host);
        ArgumentException.ThrowIfNullOrWhiteSpace(fromAddress);
        if (port is < 1 or > 65535) throw new ArgumentOutOfRangeException(nameof(port), "SMTP port must be between 1 and 65535.");

        var cleanHost = host.Trim();
        var cleanUserName = userName?.Trim() ?? string.Empty;
        var cleanFromAddress = fromAddress.Trim();
        var cleanFromName = fromName?.Trim() ?? string.Empty;
        var cleanReplyTo = replyToAddress?.Trim() ?? string.Empty;

        if (cleanHost.Length > 255) throw new ArgumentException("SMTP host is too long.", nameof(host));
        if (cleanUserName.Length > 320) throw new ArgumentException("SMTP username is too long.", nameof(userName));
        if (cleanFromAddress.Length > 320) throw new ArgumentException("From address is too long.", nameof(fromAddress));
        if (cleanFromName.Length > 160) throw new ArgumentException("From name is too long.", nameof(fromName));
        if (cleanReplyTo.Length > 320) throw new ArgumentException("Reply-to address is too long.", nameof(replyToAddress));

        UseAccountProfile = false;
        Host = cleanHost;
        Port = port;
        UserName = cleanUserName;
        FromAddress = cleanFromAddress;
        FromName = cleanFromName;
        ReplyToAddress = cleanReplyTo;
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
