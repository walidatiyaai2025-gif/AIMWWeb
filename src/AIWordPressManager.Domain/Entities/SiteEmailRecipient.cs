using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SiteEmailRecipient : AuditableEntity
{
    private SiteEmailRecipient()
    {
    }

    public SiteEmailRecipient(Guid siteId, Guid ownerUserId, string emailAddress, string? displayName, DateTime utcNow)
    {
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));

        SiteId = siteId;
        OwnerUserId = ownerUserId;
        SetEmailAddress(emailAddress, utcNow);
        SetDisplayName(displayName, utcNow);
        IsEnabled = true;
    }

    public Guid SiteId { get; private set; }
    public Guid OwnerUserId { get; private set; }
    public string EmailAddress { get; private set; } = string.Empty;
    public string NormalizedEmailAddress { get; private set; } = string.Empty;
    public string? DisplayName { get; private set; }
    public bool IsEnabled { get; private set; }

    public void Update(string emailAddress, string? displayName, bool enabled, DateTime utcNow)
    {
        SetEmailAddress(emailAddress, utcNow);
        SetDisplayName(displayName, utcNow);
        IsEnabled = enabled;
        MarkUpdated(utcNow);
    }

    public void SetEnabled(bool enabled, DateTime utcNow)
    {
        IsEnabled = enabled;
        MarkUpdated(utcNow);
    }

    private void SetEmailAddress(string emailAddress, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(emailAddress);
        var clean = emailAddress.Trim();
        if (clean.Length > 320) throw new ArgumentException("Email address is too long.", nameof(emailAddress));
        EmailAddress = clean;
        NormalizedEmailAddress = clean.ToUpperInvariant();
        MarkUpdated(utcNow);
    }

    private void SetDisplayName(string? displayName, DateTime utcNow)
    {
        var clean = string.IsNullOrWhiteSpace(displayName) ? null : displayName.Trim();
        if (clean?.Length > 120) throw new ArgumentException("Display name is too long.", nameof(displayName));
        DisplayName = clean;
        MarkUpdated(utcNow);
    }
}
