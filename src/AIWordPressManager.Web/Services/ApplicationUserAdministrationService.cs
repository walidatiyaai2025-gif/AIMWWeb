using System.Text.RegularExpressions;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationUserAdministrationService(
    AppDbContext dbContext,
    CurrentUserContext currentUser)
{
    private static readonly string[] AllowedRoles = ["Administrator", "User"];
    private readonly PasswordHasher<AuthUser> _hasher = new();

    public async Task<IReadOnlyList<ApplicationUserSummary>> ListAsync(
        string? search = null,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequireAdministrator();
        var query = dbContext.AuthUsers.AsNoTracking();
        var term = (search ?? string.Empty).Trim().ToUpperInvariant();
        if (term.Length > 0)
            query = query.Where(x => x.NormalizedUserName.Contains(term));

        return await query
            .OrderBy(x => x.UserName)
            .Select(x => new ApplicationUserSummary(
                x.Id,
                x.UserName,
                x.Role,
                x.IsActive,
                x.FailedAccessCount,
                x.LockedUntilUtc,
                x.LastLoginAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<UserAdministrationResult> CreateAsync(
        string userName,
        string password,
        string confirmPassword,
        string role,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequireAdministrator();
        var validation = Validate(userName, password, confirmPassword, role, requirePassword: true);
        if (validation is not null) return UserAdministrationResult.Failed(validation);

        var cleanUserName = userName.Trim();
        var normalized = cleanUserName.ToUpperInvariant();
        if (await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.NormalizedUserName == normalized, cancellationToken))
            return UserAdministrationResult.Failed("This username is already registered.");

        var now = DateTime.UtcNow;
        var user = new AuthUser(cleanUserName, "temporary", now, NormalizeRole(role));
        user.SetPasswordHash(_hasher.HashPassword(user, password), now);
        dbContext.AuthUsers.Add(user);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return UserAdministrationResult.Succeeded(user.Id);
        }
        catch (DbUpdateException)
        {
            return UserAdministrationResult.Failed("This username is already registered.");
        }
    }

    public async Task<UserAdministrationResult> UpdateAsync(
        Guid userId,
        string userName,
        string role,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequireAdministrator();
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");

        var validation = Validate(userName, string.Empty, string.Empty, role, requirePassword: false);
        if (validation is not null) return UserAdministrationResult.Failed(validation);

        var cleanUserName = userName.Trim();
        var normalized = cleanUserName.ToUpperInvariant();
        if (await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.Id != userId && x.NormalizedUserName == normalized, cancellationToken))
            return UserAdministrationResult.Failed("This username is already registered.");

        var normalizedRole = NormalizeRole(role);
        if (user.Role == "Administrator" && normalizedRole != "Administrator")
        {
            if (actorId == user.Id)
                return UserAdministrationResult.Failed("You cannot remove your own administrator role.");
            if (!await HasAnotherActiveAdministratorAsync(user.Id, cancellationToken))
                return UserAdministrationResult.Failed("At least one active administrator must remain.");
        }

        var now = DateTime.UtcNow;
        user.SetUserName(cleanUserName, now);
        user.SetRole(normalizedRole, now);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<UserAdministrationResult> SetActiveAsync(
        Guid userId,
        bool isActive,
        CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequireAdministrator();
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");

        if (!isActive && user.Id == actorId)
            return UserAdministrationResult.Failed("You cannot disable your own account.");
        if (!isActive && user.Role == "Administrator" && !await HasAnotherActiveAdministratorAsync(user.Id, cancellationToken))
            return UserAdministrationResult.Failed("At least one active administrator must remain.");

        user.SetActive(isActive, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<UserAdministrationResult> UnlockAsync(
        Guid userId,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequireAdministrator();
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");

        user.Unlock(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<UserAdministrationResult> ResetPasswordAsync(
        Guid userId,
        string password,
        string confirmPassword,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequireAdministrator();
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");

        var validation = Validate(user.UserName, password, confirmPassword, user.Role, requirePassword: true);
        if (validation is not null) return UserAdministrationResult.Failed(validation);

        var now = DateTime.UtcNow;
        user.SetPasswordHash(_hasher.HashPassword(user, password), now);
        user.Unlock(now);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<IReadOnlyList<LoginAuditDto>> GetLoginAuditAsync(
        Guid userId,
        int take = 50,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequireAdministrator();
        take = Math.Clamp(take, 1, 200);
        var userName = await dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id == userId)
            .Select(x => x.UserName)
            .SingleOrDefaultAsync(cancellationToken);
        if (userName is null) return [];

        return await dbContext.LoginAudits.AsNoTracking()
            .Where(x => x.UserName == userName)
            .OrderByDescending(x => x.AttemptedAtUtc)
            .Take(take)
            .Select(x => new LoginAuditDto(x.Id, x.UserName, x.Succeeded, x.Reason, x.IpAddress, x.UserAgent, x.AttemptedAtUtc))
            .ToListAsync(cancellationToken);
    }

    private Task<bool> HasAnotherActiveAdministratorAsync(Guid excludedUserId, CancellationToken cancellationToken) =>
        dbContext.AuthUsers.AsNoTracking().AnyAsync(
            x => x.Id != excludedUserId && x.IsActive && x.Role == "Administrator",
            cancellationToken);

    private static string NormalizeRole(string role) =>
        AllowedRoles.FirstOrDefault(x => string.Equals(x, role?.Trim(), StringComparison.OrdinalIgnoreCase)) ?? string.Empty;

    private static string? Validate(string userName, string password, string confirmPassword, string role, bool requirePassword)
    {
        var cleanUserName = (userName ?? string.Empty).Trim();
        if (cleanUserName.Length is < 3 or > 64)
            return "Username must be between 3 and 64 characters.";
        if (!Regex.IsMatch(cleanUserName, "^[A-Za-z0-9._-]+$"))
            return "Username can contain letters, numbers, dots, underscores, and hyphens only.";
        if (string.IsNullOrEmpty(NormalizeRole(role)))
            return "Role must be Administrator or User.";

        if (!requirePassword) return null;
        var safePassword = password ?? string.Empty;
        if (safePassword.Length < 8)
            return "Password must contain at least 8 characters.";
        if (!safePassword.Any(char.IsUpper) || !safePassword.Any(char.IsLower) || !safePassword.Any(char.IsDigit))
            return "Password must contain uppercase, lowercase, and numeric characters.";
        if (!string.Equals(safePassword, confirmPassword ?? string.Empty, StringComparison.Ordinal))
            return "Password confirmation does not match.";
        return null;
    }
}

public sealed record ApplicationUserSummary(
    Guid Id,
    string UserName,
    string Role,
    bool IsActive,
    int FailedAccessCount,
    DateTime? LockedUntilUtc,
    DateTime? LastLoginAtUtc);

public sealed record UserAdministrationResult(bool IsSuccess, string Message, Guid? UserId)
{
    public static UserAdministrationResult Failed(string message) => new(false, message, null);
    public static UserAdministrationResult Succeeded(Guid userId) => new(true, string.Empty, userId);
}
