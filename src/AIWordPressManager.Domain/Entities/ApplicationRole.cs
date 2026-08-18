using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ApplicationRole : Entity
{
    private ApplicationRole() { }

    public ApplicationRole(string name, string displayNameEnglish, string displayNameArabic, DateTime utcNow)
    {
        Rename(name, displayNameEnglish, displayNameArabic, utcNow);
        IsActive = true;
    }

    public string Name { get; private set; } = string.Empty;
    public string NormalizedName { get; private set; } = string.Empty;
    public string DisplayNameEnglish { get; private set; } = string.Empty;
    public string DisplayNameArabic { get; private set; } = string.Empty;
    public bool IsActive { get; private set; } = true;

    public void Rename(string name, string displayNameEnglish, string displayNameArabic, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(name);
        Name = name.Trim();
        NormalizedName = Name.ToUpperInvariant();
        DisplayNameEnglish = string.IsNullOrWhiteSpace(displayNameEnglish) ? Name : displayNameEnglish.Trim();
        DisplayNameArabic = string.IsNullOrWhiteSpace(displayNameArabic) ? DisplayNameEnglish : displayNameArabic.Trim();
        MarkUpdated(utcNow);
    }

    public void SetActive(bool isActive, DateTime utcNow)
    {
        IsActive = isActive;
        MarkUpdated(utcNow);
    }
}
