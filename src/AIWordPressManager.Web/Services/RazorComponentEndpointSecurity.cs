namespace AIWordPressManager.Web.Services;

/// <summary>
/// Defines the single framework bootstrap request that may bypass the application-wide
/// authenticated Razor Components policy. Both path and endpoint identity must match.
/// </summary>
public static class RazorComponentEndpointSecurity
{
    public const string BlazorWebBootstrapPath = "/_framework/blazor.web.js";
    public const string BlazorWebStaticFilesDisplayName = "Blazor web static files";

    public static bool ShouldBypassAuthorization(string? path, string? displayName)
        => string.Equals(path, BlazorWebBootstrapPath, StringComparison.Ordinal)
           && string.Equals(displayName, BlazorWebStaticFilesDisplayName, StringComparison.Ordinal);
}
