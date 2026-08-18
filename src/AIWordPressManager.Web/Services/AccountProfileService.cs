using System.Security.Claims;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class AccountProfileService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IHttpContextAccessor? httpContextAccessor = null)
{
    private readonly PasswordHasher<AuthUser> _hasher = new();
    private readonly ApplicationSessionStore _sessionStore = new(dbContext);
    private readonly ApplicationSecurityAuditService _securityAudit = new(dbContext, currentUser, httpContextAccessor);

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
        {
            await AuditPasswordAsync(user, "Failed", "Current password verification failed", null, cancellationToken);
            return PasswordChangeResult.Failed("The current password is incorrect.");
        }

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

        var currentSessionId = GetCurrentSessionId(httpContextAccessor?.HttpContext?.User);
        var activeSessions = await _sessionStore.ListAsync(user.Id, includeInactive: false, cancellationToken);
        var revokedCount = 0;
        foreach (var session in activeSessions.Where(x => !currentSessionId.HasValue || x.SessionId != currentSessionId.Value))
        {
            await _sessionStore.RevokeAsync(session.SessionId, "Password changed by account owner.", cancellationToken);
            revokedCount++;
        }

        await AuditPasswordAsync(
            user,
            "Succeeded",
            "Password changed by account owner",
            new Dictionary<string, string> { ["revokedOtherSessions"] = revokedCount.ToString() },
            cancellationToken);
        return PasswordChangeResult.Succeeded();
    }

    private Task AuditPasswordAsync(
        AuthUser user,
        string outcome,
        string reason,
        IReadOnlyDictionary<string, string>? metadata,
        CancellationToken cancellationToken)
    {
        var values = metadata is null
            ? new Dictionary<string, string>()
            : new Dictionary<string, string>(metadata);
        values["reason"] = reason;
        return _securityAudit.RecordCurrentAsync(
            "Account",
            "Password.Changed",
            outcome,
            "ApplicationUser",
            user.Id.ToString("D"),
            user.UserName,
            values,
            cancellationToken);
    }

    private static Guid? GetCurrentSessionId(ClaimsPrincipal? principal)
    {
        var value = principal?.FindFirstValue(ApplicationSessionStore.SessionIdClaimType);
        return Guid.TryParse(value, out var sessionId) ? sessionId : null;
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