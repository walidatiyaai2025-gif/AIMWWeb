namespace AIWordPressManager.Application.Sites;

public sealed record CreateSiteRequest(
    string Name,
    string SiteUrl,
    string UserName,
    string ApplicationPassword,
    string? HomeUrl,
    string? WordPressVersion,
    string? LanguageCode);
