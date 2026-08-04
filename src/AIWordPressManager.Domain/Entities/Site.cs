using AIWordPressManager.Domain.Common;
using AIWordPressManager.Domain.Enums;

namespace AIWordPressManager.Domain.Entities;

public sealed class Site : SoftDeletableEntity, IAggregateRoot
{
    private Site()
    {
    }

    public Site(string name, Uri siteUrl, DateTime utcNow)
    {
        SetName(name, utcNow);
        SetSiteUrl(siteUrl, utcNow);
    }

    public string Name { get; private set; } = string.Empty;

    public string SiteUrl { get; private set; } = string.Empty;

    public string? HomeUrl { get; private set; }

    public string? WordPressVersion { get; private set; }

    public string? LanguageCode { get; private set; }

    public SiteConnectionStatus ConnectionStatus { get; private set; } = SiteConnectionStatus.Unknown;

    public DateTime? LastConnectionTestAtUtc { get; private set; }

    public void SetName(string name, DateTime utcNow)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(name);
        Name = name.Trim();
        MarkUpdated(utcNow);
    }

    public void SetSiteUrl(Uri siteUrl, DateTime utcNow)
    {
        ArgumentNullException.ThrowIfNull(siteUrl);

        if (siteUrl.Scheme is not ("http" or "https"))
        {
            throw new ArgumentException("Site URL must use HTTP or HTTPS.", nameof(siteUrl));
        }

        SiteUrl = siteUrl.GetLeftPart(UriPartial.Authority).TrimEnd('/');
        MarkUpdated(utcNow);
    }

    public void UpdateDiscovery(
        string? homeUrl,
        string? wordPressVersion,
        string? languageCode,
        DateTime utcNow)
    {
        HomeUrl = string.IsNullOrWhiteSpace(homeUrl) ? null : homeUrl.TrimEnd('/');
        WordPressVersion = string.IsNullOrWhiteSpace(wordPressVersion) ? null : wordPressVersion.Trim();
        LanguageCode = string.IsNullOrWhiteSpace(languageCode) ? null : languageCode.Trim();
        MarkUpdated(utcNow);
    }

    public void RecordConnectionStatus(SiteConnectionStatus status, DateTime utcNow)
    {
        ConnectionStatus = status;
        LastConnectionTestAtUtc = utcNow;
        MarkUpdated(utcNow);
    }
}
