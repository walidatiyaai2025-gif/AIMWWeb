namespace AIWordPressManager.Application.Sites;

public sealed record SiteDetailsDto(
    Guid Id,
    string Name,
    string SiteUrl,
    string? HomeUrl,
    string? WordPressVersion,
    string? LanguageCode,
    string ConnectionStatus,
    DateTime? LastConnectionTestAtUtc,
    string UserName);
