using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationRoleStore(AppDbContext dbContext)
{
    public const string SettingsKey = "Security.CustomRoles";

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<IReadOnlyList<CustomApplicationRole>> GetAsync(CancellationToken cancellationToken = default)
    {
        var value = await dbContext.ApplicationSettings
            .AsNoTracking()
            .Where(x => x.Key == SettingsKey)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);

        return DeserializeStoredRoles(value);
    }

    public async Task<IReadOnlyList<string>> ResolvePermissionsAsync(string? role, CancellationToken cancellationToken = default)
    {
        var builtIn = ApplicationPermissionCatalog.ForRole(role);
        if (builtIn.Count > 0 || IsBuiltInRole(role)) return builtIn;

        var value = await dbContext.ApplicationSettings
            .AsNoTracking()
            .Where(x => x.Key == SettingsKey)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);

        return ResolvePermissions(role, value);
    }

    public static IReadOnlyList<string> ResolvePermissions(string? role, string? storedRolesJson)
    {
        var builtIn = ApplicationPermissionCatalog.ForRole(role);
        if (builtIn.Count > 0 || IsBuiltInRole(role)) return builtIn;

        var normalized = Normalize(role);
        if (normalized.Length == 0) return [];

        var customRole = DeserializeStoredRoles(storedRolesJson)
            .FirstOrDefault(x => x.IsActive && Normalize(x.Name) == normalized);
        if (customRole is null) return [];

        return customRole.Permissions
            .Where(ApplicationPermissionCatalog.IsCustomRoleAssignable)
            .Distinct(StringComparer.Ordinal)
            .ToArray();
    }

    public async Task<string?> ResolveAssignableRoleNameAsync(string? role, CancellationToken cancellationToken = default)
    {
        if (string.Equals(role?.Trim(), "Administrator", StringComparison.OrdinalIgnoreCase)) return "Administrator";
        if (string.Equals(role?.Trim(), "User", StringComparison.OrdinalIgnoreCase)) return "User";

        var normalized = Normalize(role);
        if (normalized.Length == 0) return null;

        return (await GetAsync(cancellationToken))
            .FirstOrDefault(x => x.IsActive && Normalize(x.Name) == normalized)?.Name;
    }

    public async Task<IReadOnlyList<ApplicationRoleOption>> GetAssignableRolesAsync(CancellationToken cancellationToken = default)
    {
        var roles = new List<ApplicationRoleOption>
        {
            new("Administrator", "Administrator", "مدير النظام", true, true),
            new("User", "User", "مستخدم", true, true)
        };

        roles.AddRange((await GetAsync(cancellationToken))
            .Where(x => x.IsActive)
            .Select(x => new ApplicationRoleOption(x.Name, x.DisplayNameEnglish, x.DisplayNameArabic, true, false)));

        return roles;
    }

    public async Task SaveAsync(IReadOnlyCollection<CustomApplicationRole> roles, CancellationToken cancellationToken = default)
    {
        var normalizedNames = new HashSet<string>(StringComparer.Ordinal);
        foreach (var role in roles)
        {
            if (!IsValidStoredRole(role)) throw new InvalidOperationException($"Custom role '{role.Name}' is invalid.");
            if (!normalizedNames.Add(Normalize(role.Name))) throw new InvalidOperationException("Custom role names must be unique.");
        }

        var json = JsonSerializer.Serialize(roles.OrderBy(x => x.Name, StringComparer.OrdinalIgnoreCase), JsonOptions);
        var setting = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == SettingsKey, cancellationToken);
        var now = DateTime.UtcNow;
        if (setting is null)
        {
            dbContext.ApplicationSettings.Add(new ApplicationSetting(SettingsKey, json, now));
        }
        else
        {
            setting.SetValue(SettingsKey, json, now);
        }

        await dbContext.SaveChangesAsync(cancellationToken);
    }

    public static bool IsBuiltInRole(string? role) =>
        string.Equals(role?.Trim(), "Administrator", StringComparison.OrdinalIgnoreCase) ||
        string.Equals(role?.Trim(), "User", StringComparison.OrdinalIgnoreCase);

    public static string Normalize(string? role) => (role ?? string.Empty).Trim().ToUpperInvariant();

    private static IReadOnlyList<CustomApplicationRole> DeserializeStoredRoles(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return [];

        try
        {
            return (JsonSerializer.Deserialize<List<CustomApplicationRole>>(value, JsonOptions) ?? [])
                .Where(IsValidStoredRole)
                .OrderBy(x => x.Name, StringComparer.OrdinalIgnoreCase)
                .ToArray();
        }
        catch (JsonException)
        {
            // Security configuration fails closed if persisted JSON is damaged.
            return [];
        }
    }

    private static bool IsValidStoredRole(CustomApplicationRole role) =>
        role is not null &&
        !string.IsNullOrWhiteSpace(role.Name) &&
        !IsBuiltInRole(role.Name) &&
        role.Permissions is not null &&
        role.Permissions.All(ApplicationPermissionCatalog.IsCustomRoleAssignable);
}

public sealed record CustomApplicationRole(
    string Name,
    string DisplayNameEnglish,
    string DisplayNameArabic,
    bool IsActive,
    IReadOnlyList<string> Permissions);

public sealed record ApplicationRoleOption(
    string Name,
    string DisplayNameEnglish,
    string DisplayNameArabic,
    bool IsActive,
    bool IsBuiltIn);
