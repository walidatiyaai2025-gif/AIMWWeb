namespace AIWordPressManager.Web.Services;

/// <summary>
/// Defines the exact framework bootstrap requests that may bypass the application-wide
/// authenticated Razor Components policy. Both path and endpoint identity must match.
/// </summary>
public static class RazorComponentEndpointSecurity
{
    public const string BlazorWebBootstrapPath = "/_framework/blazor.web.js";
    public const string BlazorWebStaticFilesDisplayName = "Blazor web static files";
    public const string BlazorInitializersPath = "/_blazor/initializers";
    public const string BlazorInitializersDisplayName = "Blazor initializers";

    public static bool ShouldBypassAuthorization(string? path, string? displayName)
        => IsExactEndpoint(path, displayName, BlazorWebBootstrapPath, BlazorWebStaticFilesDisplayName)
           || IsExactEndpoint(path, displayName, BlazorInitializersPath, BlazorInitializersDisplayName);

    private static bool IsExactEndpoint(
        string? path,
        string? displayName,
        string expectedPath,
        string expectedDisplayName)
        => string.Equals(path, expectedPath, StringComparison.Ordinal)
           && string.Equals(displayName, expectedDisplayName, StringComparison.Ordinal);
}
