using System.Text.RegularExpressions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationRoleAdministrationService(
    AppDbContext dbContext,
    CurrentUserContext currentUser)
{
    private static readonly Regex RoleNamePattern = new("^[A-Za-z][A-Za-z0-9._-]{2,63}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);
    private readonly ApplicationRolePermissionResolver _resolver = new(dbContext);

    public async Task<IReadOnlyList<ApplicationRoleSummary>> ListAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);

        var usersByRole = await dbContext.AuthUsers.AsNoTracking()
            .GroupBy(x => x.Role)
            .Select(group => new { Role = group.Key, Count = group.Count() })
            .ToListAsync(cancellationToken);
        var counts = usersByRole.ToDictionary(x => x.Role, x => x.Count, StringComparer.OrdinalIgnoreCase);

        var customRoles = await dbContext.Set<ApplicationRole>().AsNoTracking().OrderBy(x => x.Name).ToListAsync(cancellationToken);
        var roleIds = customRoles.Select(x => x.Id).ToArray();
        var grants = await dbContext.Set<ApplicationRoleGrant>().AsNoTracking()
            .Where(x => roleIds.Contains(x.RoleId))
            .ToListAsync(cancellationToken);

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
            grants.Where(grant => grant.RoleId == role.Id)
                .Select(grant => grant.Permission)
                .Where(permission => ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal))
                .Distinct(StringComparer.Ordinal)
                .OrderBy(permission => permission, StringComparer.Ordinal)
                .ToArray(),
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

        var normalized = cleanName.ToUpperInvariant();
        if (await dbContext.Set<ApplicationRole>().AsNoTracking().AnyAsync(x => x.NormalizedName == normalized, cancellationToken))
            return RoleAdministrationResult.Failed("A role with this name already exists.");

        var now = DateTime.UtcNow;
        var role = new ApplicationRole(cleanName, cleanEn, cleanAr, now);
        dbContext.Set<ApplicationRole>().Add(role);
        foreach (var permission in cleanPermissions)
            dbContext.Set<ApplicationRoleGrant>().Add(new ApplicationRoleGrant(role.Id, permission, now));

        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return RoleAdministrationResult.Succeeded(role.Id);
        }
        catch (DbUpdateException)
        {
            return RoleAdministrationResult.Failed("The role could not be created because its name or grants conflict with existing data.");
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
        var role = await dbContext.Set<ApplicationRole>().SingleOrDefaultAsync(x => x.Id == roleId, cancellationToken);
        if (role is null) return RoleAdministrationResult.Failed("Custom role was not found.");

        var validation = Validate(role.Name, displayNameEn, displayNameAr, permissions, out _, out var cleanEn, out var cleanAr, out var cleanPermissions);
        if (validation is not null) return RoleAdministrationResult.Failed(validation);

        var existingGrants = await dbContext.Set<ApplicationRoleGrant>().Where(x => x.RoleId == role.Id).ToListAsync(cancellationToken);
        var currentlyManagesUsers = existingGrants.Any(x => x.Permission == ApplicationPermissionCatalog.UsersManage);
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

        role.SetDisplayNames(cleanEn, cleanAr, DateTime.UtcNow);
        dbContext.Set<ApplicationRoleGrant>().RemoveRange(existingGrants);
        var now = DateTime.UtcNow;
        foreach (var permission in cleanPermissions)
            dbContext.Set<ApplicationRoleGrant>().Add(new ApplicationRoleGrant(role.Id, permission, now));

        await dbContext.SaveChangesAsync(cancellationToken);
        return RoleAdministrationResult.Succeeded(role.Id);
    }

    public async Task<RoleAdministrationResult> DeleteAsync(Guid roleId, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var role = await dbContext.Set<ApplicationRole>().SingleOrDefaultAsync(x => x.Id == roleId, cancellationToken);
        if (role is null) return RoleAdministrationResult.Failed("Custom role was not found.");

        if (await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.Role == role.Name, cancellationToken))
            return RoleAdministrationResult.Failed("This role is assigned to one or more accounts and cannot be deleted.");

        dbContext.Set<ApplicationRole>().Remove(role);
        await dbContext.SaveChangesAsync(cancellationToken);
        return RoleAdministrationResult.Succeeded(role.Id);
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