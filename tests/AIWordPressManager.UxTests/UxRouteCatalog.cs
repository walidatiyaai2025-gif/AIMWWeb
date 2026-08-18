namespace AIWordPressManager.UxTests;

public sealed record UxRouteCase(string Key, string Path, bool HighRisk = false, bool AdminOnly = false);
public sealed record UxViewport(string Key, int Width, int Height);

public static class UxRouteCatalog
{
    public static readonly IReadOnlyList<UxRouteCase> PublicRoutes =
    [
        new("welcome", "/welcome"),
        new("login", "/login")
    ];

    public static readonly IReadOnlyList<UxRouteCase> AuthenticatedRoutes =
    [
        new("dashboard", "/", HighRisk: true),
        new("sites", "/sites", HighRisk: true),
        new("ai-center", "/ai-center", HighRisk: true),
        new("ai-usage", "/module/ai-usage"),
        new("prompt-templates", "/settings/ai-prompts", HighRisk: true, AdminOnly: true),
        new("approvals", "/approvals", HighRisk: true),
        new("account-profile", "/account/profile", HighRisk: true),
        new("account-email", "/account/email-settings", HighRisk: true),
        new("system-health", "/system-health"),
        new("build-release", "/about-build")
    ];

    public static readonly IReadOnlyList<UxViewport> Viewports =
    [
        new("phone", 390, 844),
        new("tablet", 768, 1024),
        new("desktop", 1440, 900)
    ];

    public static IEnumerable<UxRouteCase> ScreenshotRoutes => AuthenticatedRoutes.Where(x => x.HighRisk);
}
