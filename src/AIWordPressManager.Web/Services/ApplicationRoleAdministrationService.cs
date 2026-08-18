using System.Text.RegularExpressions;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationRoleAdministrationService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    ApplicationRoleStore roleStore)
{
    public async Task<IReadOnlyList<CustomApplicationRole>> ListAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return await roleStore.GetAsync(cancellationToken);
    }

    public async Task<RoleAdministrationResult> SaveAsync(
        string name,
        string displayNameEnglish,
        string displayNameArabic,
        IEnumerable<string>? permissions,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);

        var cleanName = (name ?? string.Empty).Trim();
        var validation = Validate(cleanName, displayNameEnglish, displayNameArabic, permissions);
        if (validation is not null) return RoleAdministrationResult.Failed(validation);

        var requestedPermissions = (permissions ?? [])
            .Select(x => x?.Trim() ?? string.Empty)
            .Where(x => x.Length > 0)
            .Distinct(StringComparer.Ordinal)
            .OrderBy(x => x, StringComparer.Ordinal)
            .ToArray();

        var roles = (await roleStore.GetAsync(cancellationToken)).ToList();
        var normalized = ApplicationRoleStore.Normalize(cleanName);
        var index = roles.FindIndex(x => ApplicationRoleStore.Normalize(x.Name) == normalized);
        var existing = index >= 0 ? roles[index] : null;
        var canonicalName = existing?.Name ?? cleanName;
        var updated = new CustomApplicationRole(
            canonicalName,
            CleanDisplayName(displayNameEnglish, canonicalName),
            CleanDisplayName(displayNameArabic, CleanDisplayName(displayNameEnglish, canonicalName)),
            existing?.IsActive ?? true,
            requestedPermissions);

        if (index >= 0) roles[index] = updated;
        else roles.Add(updated);

        await roleStore.SaveAsync(roles, cancellationToken);
        return RoleAdministrationResult.Succeeded(updated.Name);
    }

    public async Task<RoleAdministrationResult> SetActiveAsync(
        string name,
        bool isActive,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        var roles = (await roleStore.GetAsync(cancellationToken)).ToList();
        var normalized = ApplicationRoleStore.Normalize(name);
        var index = roles.FindIndex(x => ApplicationRoleStore.Normalize(x.Name) == normalized);
        if (index < 0) return RoleAdministrationResult.Failed("Custom role was not found.");

        var role = roles[index];
        if (!isActive && role.IsActive)
        {
            var hasAssignedUsers = await dbContext.AuthUsers.AsNoTracking()
                .AnyAsync(x => x.IsActive && x.Role == role.Name, cancellationToken);
            if (hasAssignedUsers)
                return RoleAdministrationResult.Failed("Reassign active users before disabling this role.");
        }

        roles[index] = role with { IsActive = isActive };
        await roleStore.SaveAsync(roles, cancellationToken);
        return RoleAdministrationResult.Succeeded(role.Name);
    }

    private static string? Validate(
        string name,
        string displayNameEnglish,
        string displayNameArabic,
        IEnumerable<string>? permissions)
    {
        if (name.Length is < 3 or > 64)
            return "Role name must be between 3 and 64 characters.";
        if (!Regex.IsMatch(name, "^[A-Za-z][A-Za-z0-9._-]*$"))
            return "Role name must start with a letter and contain letters, numbers, dots, underscores, or hyphens only.";
        if (ApplicationRoleStore.IsBuiltInRole(name))
            return "Administrator and User are protected built-in roles.";
        if ((displayNameEnglish ?? string.Empty).Trim().Length > 120 || (displayNameArabic ?? string.Empty).Trim().Length > 120)
            return "Role display names cannot exceed 120 characters.";

        foreach (var permission in permissions ?? [])
        {
            if (!ApplicationPermissionCatalog.IsKnown(permission))
                return $"Unknown permission '{permission}'.";
            if (!ApplicationPermissionCatalog.IsCustomRoleAssignable(permission))
                return $"Permission '{permission}' is reserved for the built-in Administrator role.";
        }

        return null;
    }

    private static string CleanDisplayName(string? value, string fallback) =>
        string.IsNullOrWhiteSpace(value) ? fallback : value.Trim();
}

public sealed record RoleAdministrationResult(bool IsSuccess, string Message, string? RoleName)
{
    public static RoleAdministrationResult Failed(string message) => new(false, message, null);
    public static RoleAdministrationResult Succeeded(string roleName) => new(true, string.Empty, roleName);
}
