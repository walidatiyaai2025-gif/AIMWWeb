using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ApplicationRole : Entity
{
    private ApplicationRole() { }

    public ApplicationRole(string name, string displayNameEn, string displayNameAr, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(name);
        Name = name.Trim();
        NormalizedName = Name.ToUpperInvariant();
        SetDisplayNames(displayNameEn, displayNameAr, utcNow);
    }

    public string Name { get; private set; } = string.Empty;
    public string NormalizedName { get; private set; } = string.Empty;
    public string DisplayNameEn { get; private set; } = string.Empty;
    public string DisplayNameAr { get; private set; } = string.Empty;

    public void SetDisplayNames(string displayNameEn, string displayNameAr, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(displayNameEn);
        ArgumentException.ThrowIfNullOrWhiteSpace(displayNameAr);
        DisplayNameEn = displayNameEn.Trim();
        DisplayNameAr = displayNameAr.Trim();
        MarkUpdated(utcNow);
    }
}