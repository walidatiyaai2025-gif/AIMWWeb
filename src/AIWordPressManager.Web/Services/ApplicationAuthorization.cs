using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;

namespace AIWordPressManager.Web.Services;

public static class ApplicationPermissions
{
    public const string UsersRead = "users.read";
    public const string UsersManage = "users.manage";
    public const string SitesRead = "sites.read";
    public const string SitesManage = "sites.manage";
    public const string ContentRead = "content.read";
    public const string ContentManage = "content.manage";
    public const string OperationsRead = "operations.read";
    public const string OperationsExecute = "operations.execute";
    public const string AutomationRead = "automation.read";
    public const string AutomationManage = "automation.manage";
    public const string AiUse = "ai.use";
    public const string AiProvidersManage = "ai.providers.manage";
    public const string ReportsRead = "reports.read";
    public const string SystemRead = "system.read";
    public const string SystemManage = "system.manage";
    public const string BackupsRead = "backups.read";
    public const string BackupsManage = "backups.manage";

    private static readonly ApplicationPermissionDefinition[] Definitions =
    [
        new(UsersRead, "View application users", "عرض مستخدمي التطبيق"),
        new(UsersManage, "Manage application users and roles", "إدارة مستخدمي التطبيق والأدوار"),
        new(SitesRead, "View connected sites", "عرض المواقع المتصلة"),
        new(SitesManage, "Manage site connections and credentials", "إدارة اتصالات المواقع وبيانات الاعتماد"),
        new(ContentRead, "View synchronized content", "عرض المحتوى المتزامن"),
        new(ContentManage, "Create and modify content", "إنشاء المحتوى وتعديله"),
        new(OperationsRead, "View operation history", "عرض سجل العمليات"),
        new(OperationsExecute, "Execute WordPress operations", "تنفيذ عمليات WordPress"),
        new(AutomationRead, "View automation schedules", "عرض جداول الأتمتة"),
        new(AutomationManage, "Manage automation schedules", "إدارة جداول الأتمتة"),
        new(AiUse, "Use AI generation features", "استخدام ميزات الذكاء الاصطناعي"),
        new(AiProvidersManage, "Manage AI providers and secrets", "إدارة موفري الذكاء الاصطناعي والأسرار"),
        new(ReportsRead, "View and export reports", "عرض التقارير وتصديرها"),
        new(SystemRead, "View system health and diagnostics", "عرض صحة النظام والتشخيص"),
        new(SystemManage, "Change system-wide settings", "تغيير إعدادات النظام العامة"),
        new(BackupsRead, "View backups", "عرض النسخ الاحتياطية"),
        new(BackupsManage, "Create, restore, and delete backups", "إنشاء النسخ الاحتياطية واستعادتها وحذفها")
    ];

    public static IReadOnlyList<ApplicationPermissionDefinition> All => Definitions;
}

public static class ApplicationRoles
{
    public const string Administrator = "Administrator";
    public const string Manager = "Manager";
    public const string Operator = "Operator";
    public const string Viewer = "Viewer";
    public const string LegacyUser = "User";

    private static readonly ApplicationRoleDefinition[] Definitions =
    [
        Create(Administrator, "Administrator", "مدير النظام", ApplicationPermissions.All.Select(x => x.Id)),
        Create(Manager, "Manager", "مدير تشغيل", ManagerPermissions()),
        Create(Operator, "Operator", "مشغّل", OperatorPermissions()),
        Create(Viewer, "Viewer", "مشاهد", ViewerPermissions()),
        // Existing databases already contain the User role. Keep it operationally compatible
        // with the pre-IDN-009 authenticated-user experience instead of silently revoking access.
        Create(LegacyUser, "Legacy user", "مستخدم قديم", ManagerPermissions(), isLegacy: true)
    ];

    public static IReadOnlyList<ApplicationRoleDefinition> All => Definitions;
    public static IReadOnlyList<string> AssignableRoleNames => Definitions.Select(x => x.Name).ToArray();

    public static string Normalize(string? role) =>
        Definitions.FirstOrDefault(x => string.Equals(x.Name, role?.Trim(), StringComparison.OrdinalIgnoreCase))?.Name
        ?? string.Empty;

    public static ApplicationRoleDefinition? Find(string? role)
    {
        var normalized = Normalize(role);
        return normalized.Length == 0 ? null : Definitions.First(x => x.Name == normalized);
    }

    public static bool HasPermission(string? role, string permission) =>
        Find(role)?.Permissions.Contains(permission) == true;

    private static ApplicationRoleDefinition Create(string name, string englishName, string arabicName, IEnumerable<string> permissions, bool isLegacy = false) =>
        new(name, englishName, arabicName, new HashSet<string>(permissions, StringComparer.Ordinal), isLegacy);

    private static IEnumerable<string> ManagerPermissions() =>
    [
        ApplicationPermissions.UsersRead,
        ApplicationPermissions.SitesRead,
        ApplicationPermissions.SitesManage,
        ApplicationPermissions.ContentRead,
        ApplicationPermissions.ContentManage,
        ApplicationPermissions.OperationsRead,
        ApplicationPermissions.OperationsExecute,
        ApplicationPermissions.AutomationRead,
        ApplicationPermissions.AutomationManage,
        ApplicationPermissions.AiUse,
        ApplicationPermissions.AiProvidersManage,
        ApplicationPermissions.ReportsRead,
        ApplicationPermissions.SystemRead,
        ApplicationPermissions.BackupsRead,
        ApplicationPermissions.BackupsManage
    ];

    private static IEnumerable<string> OperatorPermissions() =>
    [
        ApplicationPermissions.SitesRead,
        ApplicationPermissions.ContentRead,
        ApplicationPermissions.ContentManage,
        ApplicationPermissions.OperationsRead,
        ApplicationPermissions.OperationsExecute,
        ApplicationPermissions.AutomationRead,
        ApplicationPermissions.AiUse,
        ApplicationPermissions.ReportsRead,
        ApplicationPermissions.SystemRead,
        ApplicationPermissions.BackupsRead
    ];

    private static IEnumerable<string> ViewerPermissions() =>
    [
        ApplicationPermissions.SitesRead,
        ApplicationPermissions.ContentRead,
        ApplicationPermissions.OperationsRead,
        ApplicationPermissions.AutomationRead,
        ApplicationPermissions.ReportsRead,
        ApplicationPermissions.SystemRead,
        ApplicationPermissions.BackupsRead
    ];
}

public static class ApplicationAuthorization
{
    public static bool HasPermission(ClaimsPrincipal? principal, string permission)
    {
        if (principal?.Identity?.IsAuthenticated != true) return false;
        return ApplicationRoles.HasPermission(principal.FindFirstValue(ClaimTypes.Role), permission);
    }

    public static void AddPermissionPolicies(AuthorizationOptions options)
    {
        foreach (var permission in ApplicationPermissions.All)
        {
            var permissionId = permission.Id;
            options.AddPolicy(permissionId, policy =>
            {
                policy.RequireAuthenticatedUser();
                policy.RequireAssertion(context => HasPermission(context.User, permissionId));
            });
        }
    }
}

public sealed record ApplicationPermissionDefinition(string Id, string EnglishName, string ArabicName);

public sealed record ApplicationRoleDefinition(
    string Name,
    string EnglishName,
    string ArabicName,
    IReadOnlySet<string> Permissions,
    bool IsLegacy = false);
