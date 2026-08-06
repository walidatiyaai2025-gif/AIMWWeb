using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class AccountProfileService(
    AppDbContext dbContext,
    CurrentUserContext currentUser)
{
    private readonly PasswordHasher<AuthUser> _hasher = new();

    public async Task<AccountProfileView> GetAsync(CancellationToken cancellationToken = default)
    {
        var userId = currentUser.RequireUserId();
        var user = await dbContext.AuthUsers.AsNoTracking()
            .SingleOrDefaultAsync(x => x.Id == userId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The signed-in account could not be found.");

        var siteCount = await dbContext.Sites.AsNoTracking()
            .CountAsync(x => x.OwnerUserId == userId, cancellationToken);

        return new AccountProfileView(
            user.Id,
            user.UserName,
            user.Role,
            user.IsActive,
            user.LastLoginAtUtc,
            user.CreatedAtUtc,
            siteCount);
    }

    public async Task<PasswordChangeResult> ChangePasswordAsync(
        string currentPassword,
        string newPassword,
        string confirmPassword,
        CancellationToken cancellationToken = default)
    {
        var userId = currentUser.RequireUserId();
        var user = await dbContext.AuthUsers.SingleOrDefaultAsync(x => x.Id == userId, cancellationToken)
            ?? throw new UnauthorizedAccessException("The signed-in account could not be found.");

        var current = currentPassword ?? string.Empty;
        var next = newPassword ?? string.Empty;
        var confirmation = confirmPassword ?? string.Empty;

        if (_hasher.VerifyHashedPassword(user, user.PasswordHash, current) == PasswordVerificationResult.Failed)
            return PasswordChangeResult.Failed("The current password is incorrect.");

        if (next.Length < 8)
            return PasswordChangeResult.Failed("The new password must contain at least 8 characters.");

        if (!next.Any(char.IsUpper) || !next.Any(char.IsLower) || !next.Any(char.IsDigit))
            return PasswordChangeResult.Failed("The new password must contain uppercase, lowercase, and numeric characters.");

        if (!string.Equals(next, confirmation, StringComparison.Ordinal))
            return PasswordChangeResult.Failed("Password confirmation does not match.");

        if (_hasher.VerifyHashedPassword(user, user.PasswordHash, next) != PasswordVerificationResult.Failed)
            return PasswordChangeResult.Failed("The new password must be different from the current password.");

        user.SetPasswordHash(_hasher.HashPassword(user, next), DateTime.UtcNow);
        await dbContext.SaveChangesAsync(cancellationToken);
        return PasswordChangeResult.Succeeded();
    }
}

public sealed record AccountProfileView(
    Guid UserId,
    string UserName,
    string Role,
    bool IsActive,
    DateTime? LastLoginAtUtc,
    DateTime CreatedAtUtc,
    int SiteCount);

public sealed record PasswordChangeResult(bool IsSuccess, string Message)
{
    public static PasswordChangeResult Failed(string message) => new(false, message);
    public static PasswordChangeResult Succeeded() => new(true, "Password changed successfully.");
}
