using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class AccountEmailRecipient : Entity
{
    private AccountEmailRecipient() { }

    public AccountEmailRecipient(Guid ownerUserId, string emailAddress, string? displayName, DateTime utcNow)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
        OwnerUserId = ownerUserId;
        Update(emailAddress, displayName, true, utcNow);
    }

    public Guid OwnerUserId { get; private set; }
    public string EmailAddress { get; private set; } = string.Empty;
    public string NormalizedEmailAddress { get; private set; } = string.Empty;
    public string? DisplayName { get; private set; }
    public bool IsEnabled { get; private set; }

    public void Update(string emailAddress, string? displayName, bool enabled, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(emailAddress);
        var clean = emailAddress.Trim();
        if (clean.Length > 320) throw new ArgumentException("Email address is too long.", nameof(emailAddress));
        var cleanName = string.IsNullOrWhiteSpace(displayName) ? null : displayName.Trim();
        if (cleanName?.Length > 120) throw new ArgumentException("Display name is too long.", nameof(displayName));

        EmailAddress = clean;
        NormalizedEmailAddress = clean.ToUpperInvariant();
        DisplayName = cleanName;
        IsEnabled = enabled;
        MarkUpdated(utcNow);
    }
}
