namespace AIWordPressManager.Application.Sites;

public sealed record SiteConnectionDataDto(
    Guid SiteId,
    string SiteName,
    string SiteUrl,
    string UserName,
    string ApplicationPassword);
