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
    private readonly PasswordHasher<AuthUser> _hasher = new();
    private readonly ApplicationRolePermissionResolver _roleResolver = new(dbContext);

    public async Task<IReadOnlyList<ApplicationUserSummary>> ListAsync(
        string? search = null,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);
        var query = dbContext.AuthUsers.AsNoTracking();
        var term = (search ?? string.Empty).Trim().ToUpperInvariant();
        if (term.Length > 0)
            query = query.Where(x => x.NormalizedUserName.Contains(term));

        return await query.OrderBy(x => x.UserName)
            .Select(x => new ApplicationUserSummary(x.Id, x.UserName, x.Role, x.IsActive, x.FailedAccessCount, x.LockedUntilUtc, x.LastLoginAtUtc))
            .ToListAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<ApplicationRoleOption>> ListRolesAsync(CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);
        return await _roleResolver.ListRolesAsync(cancellationToken);
    }

    public async Task<UserAdministrationResult> CreateAsync(string userName, string password, string confirmPassword, string role, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var validation = ValidateIdentity(userName, password, confirmPassword, true);
        if (validation is not null) return UserAdministrationResult.Failed(validation);

        var resolvedRole = await _roleResolver.ResolveRoleNameAsync(role, cancellationToken);
        if (resolvedRole is null) return UserAdministrationResult.Failed("The selected role does not exist.");

        var cleanUserName = userName.Trim();
        var normalized = cleanUserName.ToUpperInvariant();
        if (await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.NormalizedUserName == normalized, cancellationToken))
            return UserAdministrationResult.Failed("This username is already registered.");

        var now = DateTime.UtcNow;
        var user = new AuthUser(cleanUserName, "temporary", now, resolvedRole);
        user.SetPasswordHash(_hasher.HashPassword(user, password), now);
        dbContext.AuthUsers.Add(user);
        try
        {
            await dbContext.SaveChangesAsync(cancellationToken);
            return UserAdministrationResult.Succeeded(user.Id);
        }
        catch (DbUpdateException)
        {
            dbContext.Entry(user).State = EntityState.Detached;
            return UserAdministrationResult.Failed("This username is already registered.");
        }
    }

    public async Task<UserAdministrationResult> UpdateAsync(Guid userId, string userName, string role, CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");

        var validation = ValidateIdentity(userName, string.Empty, string.Empty, false);
        if (validation is not null) return UserAdministrationResult.Failed(validation);

        var resolvedRole = await _roleResolver.ResolveRoleNameAsync(role, cancellationToken);
        if (resolvedRole is null) return UserAdministrationResult.Failed("The selected role does not exist.");

        var cleanUserName = userName.Trim();
        var normalized = cleanUserName.ToUpperInvariant();
        if (await dbContext.AuthUsers.AsNoTracking().AnyAsync(x => x.Id != userId && x.NormalizedUserName == normalized, cancellationToken))
            return UserAdministrationResult.Failed("This username is already registered.");

        var currentlyManagesUsers = await _roleResolver.HasPermissionAsync(user.Role, ApplicationPermissionCatalog.UsersManage, cancellationToken);
        var targetManagesUsers = await _roleResolver.HasPermissionAsync(resolvedRole, ApplicationPermissionCatalog.UsersManage, cancellationToken);
        if (currentlyManagesUsers && !targetManagesUsers)
        {
            if (actorId == user.Id)
            {
                var message = string.Equals(user.Role, "Administrator", StringComparison.OrdinalIgnoreCase)
                    ? "You cannot remove your own administrator role."
                    : "You cannot remove Users.Manage from your own account.";
                return UserAdministrationResult.Failed(message);
            }

            if (!await HasAnotherActiveUsersManagerAsync(user.Id, cancellationToken))
            {
                var message = string.Equals(user.Role, "Administrator", StringComparison.OrdinalIgnoreCase)
                    ? "At least one active administrator must remain."
                    : "At least one active account with Users.Manage must remain.";
                return UserAdministrationResult.Failed(message);
            }
        }

        var now = DateTime.UtcNow;
        user.SetUserName(cleanUserName, now);
        user.SetRole(resolvedRole, now);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<UserAdministrationResult> SetActiveAsync(Guid userId, bool isActive, CancellationToken cancellationToken = default)
    {
        var actorId = currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");
        if (!isActive && user.Id == actorId) return UserAdministrationResult.Failed("You cannot disable your own account.");

        if (!isActive && await _roleResolver.HasPermissionAsync(user.Role, ApplicationPermissionCatalog.UsersManage, cancellationToken) &&
            !await HasAnotherActiveUsersManagerAsync(user.Id, cancellationToken))
        {
            var message = string.Equals(user.Role, "Administrator", StringComparison.OrdinalIgnoreCase)
                ? "At least one active administrator must remain."
                : "At least one active account with Users.Manage must remain.";
            return UserAdministrationResult.Failed(message);
        }

        user.SetActive(isActive, DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<UserAdministrationResult> UnlockAsync(Guid userId, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");
        user.Unlock(DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<UserAdministrationResult> ResetPasswordAsync(Guid userId, string password, string confirmPassword, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken);
        if (user is null) return UserAdministrationResult.Failed("Application user was not found.");
        var validation = ValidateIdentity(user.UserName, password, confirmPassword, true);
        if (validation is not null) return UserAdministrationResult.Failed(validation);

        var now = DateTime.UtcNow;
        user.SetPasswordHash(_hasher.HashPassword(user, password), now);
        user.Unlock(now);
        await dbContext.SaveChangesAsync(cancellationToken);
        return UserAdministrationResult.Succeeded(user.Id);
    }

    public async Task<IReadOnlyList<LoginAuditDto>> GetLoginAuditAsync(Guid userId, int take = 50, CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);
        take = Math.Clamp(take, 1, 200);
        var userName = await dbContext.AuthUsers.AsNoTracking().Where(x => x.Id == userId).Select(x => x.UserName).SingleOrDefaultAsync(cancellationToken);
        if (userName is null) return [];
        return await dbContext.LoginAudits.AsNoTracking().Where(x => x.UserName == userName).OrderByDescending(x => x.AttemptedAtUtc).Take(take)
            .Select(x => new LoginAuditDto(x.Id, x.UserName, x.Succeeded, x.Reason, x.IpAddress, x.UserAgent, x.AttemptedAtUtc)).ToListAsync(cancellationToken);
    }

    private async Task<bool> HasAnotherActiveUsersManagerAsync(Guid excludedUserId, CancellationToken cancellationToken)
    {
        var roles = await dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id != excludedUserId && x.IsActive)
            .Select(x => x.Role)
            .Distinct()
            .ToListAsync(cancellationToken);

        foreach (var role in roles)
        {
            if (await _roleResolver.HasPermissionAsync(role, ApplicationPermissionCatalog.UsersManage, cancellationToken))
                return true;
        }
        return false;
    }

    private static string? ValidateIdentity(string userName, string password, string confirmPassword, bool requirePassword)
    {
        var cleanUserName = (userName ?? string.Empty).Trim();
        if (cleanUserName.Length is < 3 or > 64) return "Username must be between 3 and 64 characters.";
        if (!Regex.IsMatch(cleanUserName, "^[A-Za-z0-9._-]+$")) return "Username can contain letters, numbers, dots, underscores, and hyphens only.";
        if (!requirePassword) return null;
        var safePassword = password ?? string.Empty;
        if (safePassword.Length < 8) return "Password must contain at least 8 characters.";
        if (!safePassword.Any(char.IsUpper) || !safePassword.Any(char.IsLower) || !safePassword.Any(char.IsDigit)) return "Password must contain uppercase, lowercase, and numeric characters.";
        if (!string.Equals(safePassword, confirmPassword ?? string.Empty, StringComparison.Ordinal)) return "Password confirmation does not match.";
        return null;
    }
}

public sealed record ApplicationUserSummary(Guid Id, string UserName, string Role, bool IsActive, int FailedAccessCount, DateTime? LockedUntilUtc, DateTime? LastLoginAtUtc);
public sealed record UserAdministrationResult(bool IsSuccess, string Message, Guid? UserId)
{
    public static UserAdministrationResult Failed(string message) => new(false, message, null);
    public static UserAdministrationResult Succeeded(Guid userId) => new(true, string.Empty, userId);
}