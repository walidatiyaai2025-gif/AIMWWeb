using System.Security.Claims;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationSessionRequestValidator(AppDbContext dbContext)
{
    private readonly ApplicationSessionStore _sessions = new(dbContext);
    private readonly ApplicationRoleStore _roles = new(dbContext);

    public async Task<SessionRequestValidationResult> ValidateAsync(
        ClaimsPrincipal principal,
        CancellationToken cancellationToken = default)
    {
        if (principal.Identity?.IsAuthenticated != true ||
            !Guid.TryParse(principal.FindFirstValue(ClaimTypes.NameIdentifier), out var userId) ||
            !Guid.TryParse(principal.FindFirstValue(ApplicationSessionStore.SessionIdClaimType), out var sessionId))
        {
            return SessionRequestValidationResult.Invalid(null, "Missing tracked session identity.");
        }

        var stored = await _sessions.ValidateAsync(sessionId, userId, cancellationToken);
        if (!stored.IsValid)
            return SessionRequestValidationResult.Invalid(sessionId, stored.Reason);

        var user = await dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id == userId)
            .Select(x => new { x.IsActive, x.Role })
            .SingleOrDefaultAsync(cancellationToken);
        if (user is null || !user.IsActive)
            return SessionRequestValidationResult.Invalid(sessionId, "Account is unavailable or inactive.");

        var resolvedRole = await _roles.ResolveAssignableRoleNameAsync(user.Role, cancellationToken);
        if (resolvedRole is null)
            return SessionRequestValidationResult.Invalid(sessionId, "Application role is unavailable or inactive.");

        var cookieRole = principal.FindFirstValue(ClaimTypes.Role) ?? string.Empty;
        var currentPermissions = (await _roles.ResolvePermissionsAsync(resolvedRole, cancellationToken))
            .OrderBy(value => value, StringComparer.Ordinal)
            .ToArray();
        var cookiePermissions = principal.FindAll(ApplicationPermissionCatalog.ClaimType)
            .Select(claim => claim.Value)
            .Distinct(StringComparer.Ordinal)
            .OrderBy(value => value, StringComparer.Ordinal)
            .ToArray();

        if (!string.Equals(cookieRole, resolvedRole, StringComparison.Ordinal) ||
            !cookiePermissions.SequenceEqual(currentPermissions, StringComparer.Ordinal))
        {
            return SessionRequestValidationResult.Invalid(sessionId, "Account role or permissions changed.");
        }

        await _sessions.TouchAsync(sessionId, cancellationToken);
        return SessionRequestValidationResult.Valid(sessionId);
    }

    public Task MarkInvalidAsync(Guid sessionId, string reason, CancellationToken cancellationToken = default) =>
        _sessions.RevokeAsync(sessionId, reason, cancellationToken);

    public Task EndCurrentOnLogoutAsync(ClaimsPrincipal principal, CancellationToken cancellationToken = default)
    {
        var value = principal.FindFirstValue(ApplicationSessionStore.SessionIdClaimType);
        return Guid.TryParse(value, out var sessionId)
            ? _sessions.RevokeAsync(sessionId, "Signed out.", cancellationToken)
            : Task.CompletedTask;
    }
}

public sealed record SessionRequestValidationResult(bool IsValid, Guid? SessionId, string Reason)
{
    public static SessionRequestValidationResult Valid(Guid sessionId) => new(true, sessionId, string.Empty);
    public static SessionRequestValidationResult Invalid(Guid? sessionId, string reason) => new(false, sessionId, reason);
}