using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Canonical permission vocabulary and built-in role mapping for application authorization.
/// Permission decisions are derived from trusted server-side identity, never client input.
/// </summary>
public static class ApplicationPermissionCatalog
{
    public const string ClaimType = "aiwm:permission";

    public const string UsersView = "Users.View";
    public const string UsersManage = "Users.Manage";
    public const string SitesView = "Sites.View";
    public const string SitesManage = "Sites.Manage";
    public const string ContentView = "Content.View";
    public const string ContentEdit = "Content.Edit";
    public const string OperationsView = "Operations.View";
    public const string OperationsExecute = "Operations.Execute";
    public const string ApprovalsView = "Approvals.View";
    public const string ApprovalsDecide = "Approvals.Decide";
    public const string SettingsManage = "Settings.Manage";

    private static readonly string[] AllPermissions =
    [
        UsersView,
        UsersManage,
        SitesView,
        SitesManage,
        ContentView,
        ContentEdit,
        OperationsView,
        OperationsExecute,
        ApprovalsView,
        ApprovalsDecide,
        SettingsManage
    ];

    private static readonly string[] UserPermissions =
    [
        SitesView,
        SitesManage,
        ContentView,
        ContentEdit,
        OperationsView,
        OperationsExecute,
        ApprovalsView,
        ApprovalsDecide
    ];

    public static IReadOnlyList<string> All => AllPermissions;

    public static IReadOnlyList<string> ForRole(string? role)
    {
        if (string.Equals(role, "Administrator", StringComparison.OrdinalIgnoreCase))
            return AllPermissions;

        if (string.Equals(role, "User", StringComparison.OrdinalIgnoreCase))
            return UserPermissions;

        return Array.Empty<string>();
    }

    public static bool RoleHasPermission(string? role, string permission) =>
        ForRole(role).Contains(permission, StringComparer.Ordinal);

    public static bool PrincipalHasPermission(ClaimsPrincipal? principal, string permission)
    {
        if (principal?.Identity?.IsAuthenticated != true || !AllPermissions.Contains(permission, StringComparer.Ordinal))
            return false;

        if (principal.Claims.Any(claim =>
                string.Equals(claim.Type, ClaimType, StringComparison.Ordinal) &&
                string.Equals(claim.Value, permission, StringComparison.Ordinal)))
        {
            return true;
        }

        // Compatibility bridge for cookies issued before permission claims existed.
        // New sign-ins receive explicit permission claims.
        return principal.FindAll(ClaimTypes.Role)
            .Select(claim => claim.Value)
            .Any(role => RoleHasPermission(role, permission));
    }

    public static void AddPolicies(AuthorizationOptions options)
    {
        ArgumentNullException.ThrowIfNull(options);

        foreach (var permission in AllPermissions)
        {
            var requiredPermission = permission;
            options.AddPolicy(requiredPermission, policy =>
            {
                policy.RequireAuthenticatedUser();
                policy.RequireAssertion(context => PrincipalHasPermission(context.User, requiredPermission));
            });
        }
    }
}