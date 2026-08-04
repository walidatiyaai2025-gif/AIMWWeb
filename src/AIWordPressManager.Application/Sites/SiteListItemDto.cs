namespace AIWordPressManager.Application.Sites;

public sealed record SiteListItemDto(
    Guid Id,
    string Name,
    string SiteUrl,
    string ConnectionStatus,
    DateTime? LastConnectionTestAtUtc);
