using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Resolves role names and grants exclusively from trusted server-side state.
/// Built-in roles remain code-defined for backward compatibility; custom roles are persisted.
/// </summary>
public sealed class ApplicationRolePermissionResolver(AppDbContext dbContext)
{
    public async Task<string?> ResolveRoleNameAsync(string? role, CancellationToken cancellationToken = default)
    {
        var clean = (role ?? string.Empty).Trim();
        if (clean.Length == 0) return null;

        if (string.Equals(clean, "Administrator", StringComparison.OrdinalIgnoreCase)) return "Administrator";
        if (string.Equals(clean, "User", StringComparison.OrdinalIgnoreCase)) return "User";

        var normalized = clean.ToUpperInvariant();
        return await dbContext.Set<ApplicationRole>().AsNoTracking()
            .Where(x => x.NormalizedName == normalized)
            .Select(x => x.Name)
            .SingleOrDefaultAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<string>> GetPermissionsAsync(string? role, CancellationToken cancellationToken = default)
    {
        var builtIn = ApplicationPermissionCatalog.ForRole(role);
        if (builtIn.Count > 0) return builtIn;

        var clean = (role ?? string.Empty).Trim();
        if (clean.Length == 0) return Array.Empty<string>();

        var normalized = clean.ToUpperInvariant();
        var roleId = await dbContext.Set<ApplicationRole>().AsNoTracking()
            .Where(x => x.NormalizedName == normalized)
            .Select(x => (Guid?)x.Id)
            .SingleOrDefaultAsync(cancellationToken);
        if (!roleId.HasValue) return Array.Empty<string>();

        var permissions = await dbContext.Set<ApplicationRoleGrant>().AsNoTracking()
            .Where(x => x.RoleId == roleId.Value)
            .Select(x => x.Permission)
            .ToListAsync(cancellationToken);

        return permissions
            .Where(permission => ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal))
            .Distinct(StringComparer.Ordinal)
            .OrderBy(permission => permission, StringComparer.Ordinal)
            .ToArray();
    }

    public async Task<bool> HasPermissionAsync(string? role, string permission, CancellationToken cancellationToken = default)
    {
        if (!ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal)) return false;
        var permissions = await GetPermissionsAsync(role, cancellationToken);
        return permissions.Contains(permission, StringComparer.Ordinal);
    }

    public async Task<IReadOnlyList<ApplicationRoleOption>> ListRolesAsync(CancellationToken cancellationToken = default)
    {
        var customRoles = await dbContext.Set<ApplicationRole>().AsNoTracking()
            .OrderBy(x => x.Name)
            .Select(x => new ApplicationRoleOption(x.Name, x.DisplayNameEn, x.DisplayNameAr, false))
            .ToListAsync(cancellationToken);

        var roles = new List<ApplicationRoleOption>
        {
            new("Administrator", "Administrator", "مدير", true),
            new("User", "User", "مستخدم", true)
        };
        roles.AddRange(customRoles);
        return roles;
    }
}

public sealed record ApplicationRoleOption(string Name, string DisplayNameEn, string DisplayNameAr, bool IsBuiltIn);