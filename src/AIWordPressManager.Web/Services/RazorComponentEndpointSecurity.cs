namespace AIWordPressManager.Web.Services;

/// <summary>
/// Keeps the Razor Components application protected while allowing the framework bootstrap
/// asset endpoint to remain reachable before authentication.
/// </summary>
public static class RazorComponentEndpointSecurity
{
    public const string BlazorWebStaticFilesDisplayName = "Blazor web static files";

    public static bool ShouldAllowAnonymous(string? displayName)
        => string.Equals(displayName, BlazorWebStaticFilesDisplayName, StringComparison.Ordinal);
}
