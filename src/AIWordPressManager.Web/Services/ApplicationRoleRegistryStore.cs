using System.Text.Json;
using System.Text.RegularExpressions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationRoleRegistryStore(AppDbContext dbContext)
{
    public const string RegistryKey = "Security.CustomRoles.v1";
    private const int CurrentVersion = 1;
    private static readonly Regex RoleNamePattern = new("^[A-Za-z][A-Za-z0-9._-]{2,63}$", RegexOptions.Compiled | RegexOptions.CultureInvariant);
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<IReadOnlyList<PersistedApplicationRole>> LoadAsync(CancellationToken cancellationToken = default)
    {
        var value = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == RegistryKey)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);
        if (string.IsNullOrWhiteSpace(value)) return Array.Empty<PersistedApplicationRole>();

        try
        {
            var document = JsonSerializer.Deserialize<RoleRegistryDocument>(value, JsonOptions);
            if (document is null || document.Version != CurrentVersion || document.Roles is null)
                throw new InvalidOperationException("Custom role registry version is unsupported or incomplete.");

            ValidateDocument(document.Roles);
            return document.Roles
                .OrderBy(role => role.Name, StringComparer.OrdinalIgnoreCase)
                .ToArray();
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("Custom role registry is not valid JSON.", ex);
        }
    }

    public async Task SaveAsync(IReadOnlyCollection<PersistedApplicationRole> roles, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(roles);
        var normalized = roles
            .OrderBy(role => role.Name, StringComparer.OrdinalIgnoreCase)
            .ToArray();
        ValidateDocument(normalized);

        var value = JsonSerializer.Serialize(new RoleRegistryDocument(CurrentVersion, normalized), JsonOptions);
        var setting = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == RegistryKey, cancellationToken);
        var now = DateTime.UtcNow;
        if (setting is null)
            dbContext.ApplicationSettings.Add(new ApplicationSetting(RegistryKey, value, now));
        else
            setting.SetValue(RegistryKey, value, now);

        await dbContext.SaveChangesAsync(cancellationToken);
    }

    private static void ValidateDocument(IEnumerable<PersistedApplicationRole> roles)
    {
        var materialized = roles.ToArray();
        if (materialized.Any(role => role.Id == Guid.Empty || !RoleNamePattern.IsMatch(role.Name ?? string.Empty)))
            throw new InvalidOperationException("Custom role registry contains an invalid role identity.");

        if (materialized.GroupBy(role => role.Id).Any(group => group.Count() > 1) ||
            materialized.GroupBy(role => role.Name, StringComparer.OrdinalIgnoreCase).Any(group => group.Count() > 1))
            throw new InvalidOperationException("Custom role registry contains duplicate roles.");

        foreach (var role in materialized)
        {
            if (string.Equals(role.Name, "Administrator", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(role.Name, "User", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Custom role registry cannot replace a built-in role.");

            if (string.IsNullOrWhiteSpace(role.DisplayNameEn) || role.DisplayNameEn.Trim().Length is < 2 or > 120 ||
                string.IsNullOrWhiteSpace(role.DisplayNameAr) || role.DisplayNameAr.Trim().Length is < 2 or > 120)
                throw new InvalidOperationException("Custom role registry contains invalid display names.");

            if (role.Permissions is null || role.Permissions.Any(permission => !ApplicationPermissionCatalog.All.Contains(permission, StringComparer.Ordinal)))
                throw new InvalidOperationException("Custom role registry contains an unknown permission.");
        }
    }

    private sealed record RoleRegistryDocument(int Version, IReadOnlyList<PersistedApplicationRole> Roles);
}

public sealed record PersistedApplicationRole(
    Guid Id,
    string Name,
    string DisplayNameEn,
    string DisplayNameAr,
    IReadOnlyList<string> Permissions);