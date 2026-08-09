namespace AIWordPressManager.Web.Services;

public static class PublicEntryRouting
{
    public const string LandingPath = "/welcome";

    public static bool ShouldRedirectToLanding(string? path, string? method, bool isAuthenticated)
    {
        if (isAuthenticated) return false;
        if (!HttpMethods.IsGet(method) && !HttpMethods.IsHead(method)) return false;
        return string.Equals(path, "/", StringComparison.Ordinal);
    }
}
