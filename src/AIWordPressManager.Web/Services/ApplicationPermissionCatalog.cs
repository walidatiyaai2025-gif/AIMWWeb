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

    private static readonly ApplicationPermissionDefinition[] PermissionDefinitions =
    [
        new(UsersView, "View application users", "عرض مستخدمي التطبيق", true),
        new(UsersManage, "Manage users and security roles", "إدارة المستخدمين وأدوار الأمان", false),
        new(SitesView, "View connected sites", "عرض المواقع المتصلة", true),
        new(SitesManage, "Manage site connections", "إدارة اتصالات المواقع", true),
        new(ContentView, "View synchronized content", "عرض المحتوى المتزامن", true),
        new(ContentEdit, "Create and edit content", "إنشاء المحتوى وتعديله", true),
        new(OperationsView, "View operational status", "عرض الحالة التشغيلية", true),
        new(OperationsExecute, "Execute operational jobs", "تنفيذ المهام التشغيلية", true),
        new(ApprovalsView, "View approval queue", "عرض قائمة الموافقات", true),
        new(ApprovalsDecide, "Approve or reject changes", "الموافقة على التغييرات أو رفضها", true),
        new(SettingsManage, "Manage security-sensitive settings", "إدارة الإعدادات الحساسة أمنيًا", false)
    ];

    private static readonly string[] AllPermissions = PermissionDefinitions.Select(x => x.Id).ToArray();

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
    public static IReadOnlyList<ApplicationPermissionDefinition> Definitions => PermissionDefinitions;

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

    public static bool IsKnown(string? permission) =>
        !string.IsNullOrWhiteSpace(permission) && AllPermissions.Contains(permission, StringComparer.Ordinal);

    /// <summary>
    /// Custom roles can receive operational permissions, including read-only user visibility,
    /// but cannot receive account/security administration capabilities. Those remain exclusive
    /// to the built-in Administrator role so custom grants cannot bypass last-admin safeguards.
    /// </summary>
    public static bool IsCustomRoleAssignable(string? permission) =>
        IsKnown(permission) &&
        !string.Equals(permission, UsersManage, StringComparison.Ordinal) &&
        !string.Equals(permission, SettingsManage, StringComparison.Ordinal);

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
        // New sign-ins receive explicit permission claims. Custom roles intentionally have no
        // role-name fallback and therefore fail closed when a stale cookie lacks explicit grants.
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

public sealed record ApplicationPermissionDefinition(
    string Id,
    string EnglishName,
    string ArabicName,
    bool CustomRoleAssignable);
