using System.Text.RegularExpressions;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationRoleAdministrationService(
    AppDbContext dbContext,
    CurrentUserContext currentUser)
{
    private static readonly Regex RoleNamePattern = new("^[A-Za-z][A-Za-z0-9._-]{2,63}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);
    private readonly ApplicationRolePermissionResolver _resolver = new(dbContext);
    private readonly ApplicationRoleRegistryStore _store = new(dbContext);

    public async Task<IReadOnlyList<ApplicationRoleSummary>> ListAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);

        var usersByRole = await dbContext.AuthUsers.AsNoTracking()
            .GroupBy(x => x.Role)
            .Select(group => new { Role = group.Key, Count = group.Count() })
            .ToListAsync(cancellationToken);
        var counts = usersByRole.ToDictionary(x => x.Role, x => x.Count, StringComparer.OrdinalIgnoreCase);
        var customRoles = await _store.LoadAsync(cancellationToken);

        var result = new List<ApplicationRoleSummary>
        {
            new(null, "Administrator", "Administrator", "مدير", true, ApplicationPermissionCatalog.ForRole("Administrator"), counts.GetValueOrDefault("Administrator")),
            new(null, "User", "User", "مستخدم", true, ApplicationPermissionCatalog.ForRole("User"), counts.GetValueOrDefault("User"))
        };

        result.AddRange(customRoles.Select(role => new ApplicationRoleSummary(
            role.Id,
            role.Name,
            role.DisplayNameEn,
            role.DisplayNameAr,
            false,
            role.Permissions,
            counts.GetValueOrDefault(role.Name))));
        return result;
    }

    public async Task<RoleAdministrationResult> CreateAsync(
        string name,
        string displayNameEn,
        string displayNameAr,
        IEnumerable<string> permissions,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var validation = Validate(name, displayNameEn, displayNameAr, permissions, out var cleanName, out var cleanEn, out var cleanAr, out var cleanPermissions);
        if (validation is not null) return RoleAdministrationResult.Failed(validation);
        if (IsBuiltInRole(cleanName)) return RoleAdministrationResult.Failed("Built-in roles cannot be recreated or replaced.");

        IReadOnlyList<PersistedApplicationRole> roles;
        try
        {
            roles = await _store.LoadAsync(cancellationToken);
        }
        catch (InvalidOperationException)
        {
            return RoleAdministrationResult.Failed("The custom role registry is invalid and must be repaired before roles can be changed.");
        }

        if (roles.Any(role => string.Equals(role.Name, cleanName, StringComparison.OrdinalIgnoreCase)))
            return RoleAdministrationResult.Failed("A role with this name already exists.");

        var role = new PersistedApplicationRole(Guid.NewGuid(), cleanName, cleanEn, cleanAr, cleanPermissions);
        var updated = roles.Append(role).ToArray();
        try
        {
            await _store.SaveAsync(updated, cancellationToken);
            return RoleAdministrationResult.Succeeded(role.Id);
        }
        catch (DbUpdateConcurrencyException)
        {
            return RoleAdministrationResult.Failed("Roles changed concurrently. Refresh and try again.");
        }
        catch (InvalidOperationException)
        {
            return RoleAdministrationResult.Failed("The custom role registry could not be validated.");
        }
    }

    public async Task<RoleAdministrationResult> UpdateAsync(
        Guid roleId,
        string displayNameEn,
        string displayNameAr,
        IEnumerable<string> permissions,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        IReadOnlyList<PersistedApplicationRole> roles;
        try
        {
            roles = await _store.LoadAsync(cancellationToken);
        }
        catch (InvalidOperationException)
        {
            return RoleAdministrationResult.Failed("The custom role registry is invalid and must be repaired before roles can be changed.");
        }

        var role = roles.FirstOrDefault(item => item.Id == roleId);
        if (role is null) return RoleAdministrationResult.Failed("Custom role was not found.");

        var validation = Validate(role.Name, displayNameEn, displayNameAr, permissions, out _, out var cleanEn, out var cleanAr, out var cleanPermissions);
        if (validation is not null) return RoleAdministrationResult.Failed(validation);

        var currentlyManagesUsers = role.Permissions.Contains(ApplicationPermissionCatalog.UsersManage, StringComparer.Ordinal);
        var willManageUsers = cleanPermissions.Contains(ApplicationPermissionCatalog.UsersManage, StringComparer.Ordinal);
        if (currentlyManagesUsers && !willManageUsers)
        {
            var affectedActiveUsers = await dbContext.AuthUsers.AsNoTracking()
                .Where(x => x.IsActive && x.Role == role.Name)
                .Select(x => x.Id)
                .ToListAsync(cancellationToken);

            if (affectedActiveUsers.Contains(actorId))
                return RoleAdministrationResult.Failed("You cannot remove Users.Manage from your own active role.");

            if (affectedActiveUsers.Count > 0 && !await HasActiveUsersManagerOutsideRoleAsync(role.Name, cancellationToken))
                return RoleAdministrationResult.Failed("At least one active account with Users.Manage must remain.");
        }

        var replacement = role with { DisplayNameEn = cleanEn, DisplayNameAr = cleanAr, Permissions = cleanPermissions };
        var updated = roles.Select(item => item.Id == roleId ? replacement : item).ToArray();
        try
        {
            await _store.SaveAsync(updated, cancellationToken);
            return RoleAdministrationResult.Succeeded(role.Id);
        }
        catch (DbUpdateConcurrencyException)
        {
            return RoleAdministrationResult.Failed("Roles changed concurrently. Refresh and try again.");
        }
    }

    public async Task<RoleAdministrationResult> DeleteAsync(Guid roleId, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        IReadOnlyList<PersistedApplicationRole> roles;
        try
        {
            roles = await _store.LoadAsync(cancellationToken);
        }
        catch (InvalidOperationException)
        {
            return RoleAdministrationResult.Failed("The custom role registry is invalid and must be repaired before roles can be changed.");
        }

        var role = roles.FirstOrDefault(item => item.Id == roleId);
        if (role is null) return RoleAdministrationResult.Failed("Custom role was not found.");
        if (await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.Role == role.Name, cancellationToken))
            return RoleAdministrationResult.Failed("This role is assigned to one or more accounts and cannot be deleted.");

        try
        {
            await _store.SaveAsync(roles.Where(item => item.Id != roleId).ToArray(), cancellationToken);
            return RoleAdministrationResult.Succeeded(role.Id);
        }
        catch (DbUpdateConcurrencyException)
        {
            return RoleAdministrationResult.Failed("Roles changed concurrently. Refresh and try again.");
        }
    }

    private async Task<bool> HasActiveUsersManagerOutsideRoleAsync(string excludedRole, CancellationToken cancellationToken)
    {
        var roles = await dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.IsActive && x.Role != excludedRole)
            .Select(x => x.Role)
            .Distinct()
            .ToListAsync(cancellationToken);

        foreach (var role in roles)
        {
            if (await _resolver.HasPermissionAsync(role, ApplicationPermissionCatalog.UsersManage, cancellationToken))
                return true;
        }
        return false;
    }

    private static string? Validate(
        string name,
        string displayNameEn,
        string displayNameAr,
        IEnumerable<string> permissions,
        out string cleanName,
        out string cleanEn,
        out string cleanAr,
        out string[] cleanPermissions)
    {
        cleanName = (name ?? string.Empty).Trim();
        cleanEn = (displayNameEn ?? string.Empty).Trim();
        cleanAr = (displayNameAr ?? string.Empty).Trim();
        cleanPermissions = (permissions ?? Array.Empty<string>())
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Select(x => x.Trim())
            .Distinct(StringComparer.Ordinal)
            .OrderBy(x => x, StringComparer.Ordinal)
            .ToArray();

        if (!RoleNamePattern.IsMatch(cleanName)) return "Role name must be 3-64 characters, start with a letter, and use letters, numbers, dots, underscores, or hyphens only.";
        if (cleanEn.Length is < 2 or > 120) return "English display name must be between 2 and 120 characters.";
        if (cleanAr.Length is < 2 or > 120) return "Arabic display name must be between 2 and 120 characters.";
        if (cleanPermissions.Any(permission => !ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal))) return "Role contains an unknown permission.";
        return null;
    }

    private static bool IsBuiltInRole(string role) =>
        string.Equals(role, "Administrator", StringComparison.OrdinalIgnoreCase) ||
        string.Equals(role, "User", StringComparison.OrdinalIgnoreCase);
}

public sealed record ApplicationRoleSummary(
    Guid? Id,
    string Name,
    string DisplayNameEn,
    string DisplayNameAr,
    bool IsBuiltIn,
    IReadOnlyList<string> Permissions,
    int AssignedUsers);

public sealed record RoleAdministrationResult(bool IsSuccess, string Message, Guid? RoleId)
{
    public static RoleAdministrationResult Failed(string message) => new(false, message, null);
    public static RoleAdministrationResult Succeeded(Guid roleId) => new(true, string.Empty, roleId);
}