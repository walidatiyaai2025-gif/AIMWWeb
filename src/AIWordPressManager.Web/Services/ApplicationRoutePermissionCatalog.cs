namespace AIWordPressManager.Web.Services;

/// <summary>
/// Defines minimum permissions for interactive Blazor route components that don't yet
/// carry their own explicit policy metadata. This is an additional route boundary; component
/// attributes and service-level mutation checks remain authoritative independently.
/// </summary>
public static class ApplicationRoutePermissionCatalog
{
    private static readonly IReadOnlyDictionary<string, string> RequiredPermissions =
        new Dictionary<string, string>(StringComparer.Ordinal)
        {
            ["Sites"] = ApplicationPermissionCatalog.SitesView,
            ["GlobalContentHub"] = ApplicationPermissionCatalog.ContentView,
            ["ExecutionCenter"] = ApplicationPermissionCatalog.OperationsView,
            ["ApprovalQueue"] = ApplicationPermissionCatalog.ApprovalsView,
            ["SiteOperationsHub"] = ApplicationPermissionCatalog.OperationsView,
            ["SiteOperationsOverview"] = ApplicationPermissionCatalog.OperationsView,
            ["SiteReliability"] = ApplicationPermissionCatalog.OperationsView,
            ["SiteOperationDetails"] = ApplicationPermissionCatalog.OperationsView,
            ["SiteOperationsMaintenance"] = ApplicationPermissionCatalog.OperationsExecute,
            ["LogsAndErrors"] = ApplicationPermissionCatalog.SettingsManage
        };

    public static string? For(Type? pageType)
    {
        if (pageType is null)
            return null;

        return RequiredPermissions.TryGetValue(pageType.Name, out var permission)
            ? permission
            : null;
    }

    public static IReadOnlyDictionary<string, string> All => RequiredPermissions;
}
