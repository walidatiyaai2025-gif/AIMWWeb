using AIWordPressManager.Persistence;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Resolves role names and grants exclusively from trusted server-side state.
/// Built-in roles remain code-defined for backward compatibility; custom roles are persisted
/// in the provider-neutral application settings registry.
/// </summary>
public sealed class ApplicationRolePermissionResolver(AppDbContext dbContext)
{
    private readonly ApplicationRoleRegistryStore _store = new(dbContext);

    public async Task<string?> ResolveRoleNameAsync(string? role, CancellationToken cancellationToken = default)
    {
        var clean = (role ?? string.Empty).Trim();
        if (clean.Length == 0) return null;

        if (string.Equals(clean, "Administrator", StringComparison.OrdinalIgnoreCase)) return "Administrator";
        if (string.Equals(clean, "User", StringComparison.OrdinalIgnoreCase)) return "User";

        try
        {
            var roles = await _store.LoadAsync(cancellationToken);
            return roles.FirstOrDefault(item => string.Equals(item.Name, clean, StringComparison.OrdinalIgnoreCase))?.Name;
        }
        catch (InvalidOperationException)
        {
            return null;
        }
    }

    public async Task<IReadOnlyList<string>> GetPermissionsAsync(string? role, CancellationToken cancellationToken = default)
    {
        var builtIn = ApplicationPermissionCatalog.ForRole(role);
        if (builtIn.Count > 0) return builtIn;

        var clean = (role ?? string.Empty).Trim();
        if (clean.Length == 0) return Array.Empty<string>();

        try
        {
            var roles = await _store.LoadAsync(cancellationToken);
            var customRole = roles.FirstOrDefault(item => string.Equals(item.Name, clean, StringComparison.OrdinalIgnoreCase));
            if (customRole is null) return Array.Empty<string>();

            return customRole.Permissions
                .Where(permission => ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal))
                .Distinct(StringComparer.Ordinal)
                .OrderBy(permission => permission, StringComparer.Ordinal)
                .ToArray();
        }
        catch (InvalidOperationException)
        {
            // A malformed or unsupported registry must never grant authority.
            return Array.Empty<string>();
        }
    }

    public async Task<bool> HasPermissionAsync(string? role, string permission, CancellationToken cancellationToken = default)
    {
        if (!ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal)) return false;
        var permissions = await GetPermissionsAsync(role, cancellationToken);
        return permissions.Contains(permission, StringComparer.Ordinal);
    }

    public async Task<IReadOnlyList<ApplicationRoleOption>> ListRolesAsync(CancellationToken cancellationToken = default)
    {
        IReadOnlyList<PersistedApplicationRole> customRoles;
        try
        {
            customRoles = await _store.LoadAsync(cancellationToken);
        }
        catch (InvalidOperationException)
        {
            customRoles = Array.Empty<PersistedApplicationRole>();
        }

        var roles = new List<ApplicationRoleOption>
        {
            new("Administrator", "Administrator", "مدير", true),
            new("User", "User", "مستخدم", true)
        };
        roles.AddRange(customRoles.Select(role => new ApplicationRoleOption(role.Name, role.DisplayNameEn, role.DisplayNameAr, false)));
        return roles;
    }
}

public sealed record ApplicationRoleOption(string Name, string DisplayNameEn, string DisplayNameAr, bool IsBuiltIn);