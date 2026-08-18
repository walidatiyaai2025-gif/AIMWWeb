using System.Security.Claims;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Maps navigation destinations to the minimum application permission required to expose them.
/// Navigation filtering is a usability boundary only; route and service authorization remain
/// independently authoritative.
/// </summary>
public static class ApplicationNavigationPermissionCatalog
{
    private static readonly IReadOnlyDictionary<string, string> RequiredPermissions =
        new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["/sites"] = ApplicationPermissionCatalog.SitesView,
            ["/sites/connect"] = ApplicationPermissionCatalog.SitesManage,
            ["/content"] = ApplicationPermissionCatalog.ContentView,
            ["/module/posts"] = ApplicationPermissionCatalog.ContentView,
            ["/module/pages"] = ApplicationPermissionCatalog.ContentView,
            ["/module/media"] = ApplicationPermissionCatalog.ContentView,
            ["/module/taxonomy"] = ApplicationPermissionCatalog.ContentView,
            ["/module/comments"] = ApplicationPermissionCatalog.ContentView,
            ["/module/users"] = ApplicationPermissionCatalog.ContentView,
            ["/module/execution"] = ApplicationPermissionCatalog.OperationsView,
            ["/module/approvals"] = ApplicationPermissionCatalog.ApprovalsView
        };

    public static string? ForPath(string? path)
    {
        if (string.IsNullOrWhiteSpace(path))
            return null;

        var normalized = Normalize(path);
        return RequiredPermissions
            .Where(entry => Matches(entry.Key, normalized))
            .OrderByDescending(entry => entry.Key.Length)
            .Select(entry => entry.Value)
            .FirstOrDefault();
    }

    public static bool CanAccess(ClaimsPrincipal? principal, string? path)
    {
        var permission = ForPath(path);
        return permission is null || ApplicationPermissionCatalog.PrincipalHasPermission(principal, permission);
    }

    public static IReadOnlyDictionary<string, string> All => RequiredPermissions;

    private static bool Matches(string configuredPath, string candidatePath) =>
        string.Equals(configuredPath, candidatePath, StringComparison.OrdinalIgnoreCase) ||
        candidatePath.StartsWith(configuredPath + "/", StringComparison.OrdinalIgnoreCase);

    private static string Normalize(string path)
    {
        var queryIndex = path.IndexOfAny(['?', '#']);
        if (queryIndex >= 0)
            path = path[..queryIndex];

        if (!path.StartsWith('/'))
            path = "/" + path;

        return path.Length > 1 ? path.TrimEnd('/') : path;
    }
}
