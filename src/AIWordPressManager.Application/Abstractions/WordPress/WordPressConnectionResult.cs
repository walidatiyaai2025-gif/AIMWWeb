namespace AIWordPressManager.Application.Abstractions.WordPress;

public sealed record WordPressConnectionResult(
    bool IsSuccess,
    string Message,
    string? SiteName = null,
    string? HomeUrl = null,
    string? WordPressVersion = null,
    string? LanguageCode = null,
    int? CurrentUserId = null,
    string? Diagnostics = null);
