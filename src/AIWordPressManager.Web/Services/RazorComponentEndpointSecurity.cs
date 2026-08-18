namespace AIWordPressManager.Web.Services;

/// <summary>
/// Defines the exact framework bootstrap request paths that may bypass the application-wide
/// authenticated Razor Components policy. The paths are framework-reserved and are matched
/// case-sensitively so the exception cannot expand to nearby application or Blazor endpoints.
/// </summary>
public static class RazorComponentEndpointSecurity
{
    public const string BlazorWebBootstrapPath = "/_framework/blazor.web.js";
    public const string BlazorInitializersPath = "/_blazor/initializers";

    public static bool ShouldBypassAuthorization(string? path, string? displayName)
        => IsExactBootstrapPath(path, BlazorWebBootstrapPath)
           || IsExactBootstrapPath(path, BlazorInitializersPath);

    private static bool IsExactBootstrapPath(string? path, string expectedPath)
        => string.Equals(path, expectedPath, StringComparison.Ordinal);
}
