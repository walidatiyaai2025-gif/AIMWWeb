namespace AIWordPressManager.Application.Abstractions.WordPress;

public sealed record WordPressConnectionRequest(string SiteUrl, string UserName, string ApplicationPassword);
