using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.Abstractions.WordPress;

public interface IWordPressThemeService
{
    Task<Result<WordPressThemeDiscoveryResult>> DiscoverAsync(Guid siteId, CancellationToken cancellationToken = default);
}

public sealed record WordPressThemeDiscoveryResult(
    WordPressThemeInfo? ActiveTheme,
    IReadOnlyList<string> DetectedCapabilities,
    string DiscoveryMethod,
    string Notes,
    DateTimeOffset CheckedAt);

public sealed record WordPressThemeInfo(
    string Name,
    string Stylesheet,
    string Template,
    string Version,
    string Author,
    string Status,
    bool IsBlockTheme,
    string ScreenshotUrl);
