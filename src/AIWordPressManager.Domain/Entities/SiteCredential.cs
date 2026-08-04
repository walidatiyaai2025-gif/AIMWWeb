using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SiteCredential : Entity
{
    private SiteCredential() { }

    public SiteCredential(Guid siteId, string userName, string protectedApplicationPassword, DateTime utcNow)
    {
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        SetUserName(userName, utcNow);
        SetProtectedApplicationPassword(protectedApplicationPassword, utcNow);
        SiteId = siteId;
    }

    public Guid SiteId { get; private set; }
    public Site Site { get; private set; } = null!;
    public string UserName { get; private set; } = string.Empty;
    public string ProtectedApplicationPassword { get; private set; } = string.Empty;

    public void SetUserName(string userName, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(userName);
        UserName = userName.Trim();
        MarkUpdated(utcNow);
    }

    public void SetProtectedApplicationPassword(string value, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(value);
        ProtectedApplicationPassword = value;
        MarkUpdated(utcNow);
    }
}
