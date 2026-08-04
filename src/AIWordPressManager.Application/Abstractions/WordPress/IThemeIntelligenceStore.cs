namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IThemeIntelligenceStore
{
    Task<ThemeIntelligenceProfile?> GetAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task SaveAsync(ThemeIntelligenceProfile profile, CancellationToken cancellationToken = default);
}

public sealed record ThemeIntelligenceProfile(
    Guid SiteId,
    string ThemeName,
    string Stylesheet,
    string Template,
    string Version,
    string Author,
    bool IsBlockTheme,
    string ThemeFamily,
    string RecommendedAdapter,
    string SafeChangeStrategy,
    string RiskSummary,
    string Capabilities,
    string DiscoveryMethod,
    string Notes,
    DateTime UpdatedAtUtc);
