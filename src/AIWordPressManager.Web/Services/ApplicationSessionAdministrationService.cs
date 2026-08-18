using System.Security.Claims;
using AIWordPressManager.Persistence;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationSessionAdministrationService(
    ApplicationSessionStore sessionStore,
    CurrentUserContext currentUser,
    IHttpContextAccessor httpContextAccessor,
    AppDbContext? dbContext = null)
{
    private readonly ApplicationSecurityAuditService? _securityAudit = dbContext is null
        ? null
        : new ApplicationSecurityAuditService(dbContext, currentUser, httpContextAccessor);

    public async Task<IReadOnlyList<ApplicationSessionSummary>> ListAllAsync(
        bool includeInactive = false,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersView);
        return Map(await sessionStore.ListAsync(null, includeInactive, cancellationToken));
    }

    public async Task<IReadOnlyList<ApplicationSessionSummary>> ListMineAsync(
        bool includeInactive = false,
        CancellationToken cancellationToken = default)
    {
        var userId = RequireHttpUserId();
        return Map(await sessionStore.ListAsync(userId, includeInactive, cancellationToken));
    }

    public async Task<SessionAdministrationResult> EndSessionAsync(
        Guid sessionId,
        string reason,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var session = await sessionStore.TryGetAsync(sessionId, cancellationToken);
        if (session is null) return SessionAdministrationResult.Failed("Session was not found.");

        await sessionStore.RevokeAsync(sessionId, reason, cancellationToken);
        if (_securityAudit is not null)
        {
            await _securityAudit.RecordCurrentAsync(
                "Session",
                "Session.Revoked",
                "Succeeded",
                "ApplicationSession",
                session.SessionId.ToString("D"),
                session.UserName,
                new Dictionary<string, string>
                {
                    ["targetUserId"] = session.UserId.ToString("D"),
                    ["reason"] = reason
                },
                cancellationToken);
        }
        return SessionAdministrationResult.Succeeded(sessionId);
    }

    public async Task<SessionAdministrationResult> EndUserSessionsAsync(
        Guid userId,
        string reason,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        if (userId == Guid.Empty) return SessionAdministrationResult.Failed("User ID is required.");

        var activeSessions = await sessionStore.ListAsync(userId, includeInactive: false, cancellationToken);
        if (activeSessions.Count == 0)
            return SessionAdministrationResult.Failed("No active sessions were found for this account.");

        await sessionStore.RevokeUserAsync(userId, reason, cancellationToken);
        if (_securityAudit is not null)
        {
            await _securityAudit.RecordCurrentAsync(
                "Session",
                "Session.UserBulkRevoked",
                "Succeeded",
                "ApplicationUser",
                userId.ToString("D"),
                activeSessions[0].UserName,
                new Dictionary<string, string>
                {
                    ["sessionCount"] = activeSessions.Count.ToString(),
                    ["reason"] = reason
                },
                cancellationToken);
        }
        return SessionAdministrationResult.Succeeded(Guid.Empty);
    }

    public async Task<SessionAdministrationResult> EndMySessionAsync(
        Guid sessionId,
        string reason,
        CancellationToken cancellationToken = default)
    {
        var userId = RequireHttpUserId();
        var session = await sessionStore.TryGetAsync(sessionId, cancellationToken);
        if (session is null || session.UserId != userId)
            return SessionAdministrationResult.Failed("Session was not found for the current account.");

        await sessionStore.RevokeAsync(sessionId, reason, cancellationToken);
        if (_securityAudit is not null)
        {
            await _securityAudit.RecordCurrentAsync(
                "Session",
                "Session.SelfRevoked",
                "Succeeded",
                "ApplicationSession",
                session.SessionId.ToString("D"),
                session.UserName,
                new Dictionary<string, string> { ["reason"] = reason },
                cancellationToken);
        }
        return SessionAdministrationResult.Succeeded(sessionId);
    }

    private IReadOnlyList<ApplicationSessionSummary> Map(IReadOnlyList<ApplicationSessionRecord> sessions)
    {
        var currentSessionId = GetCurrentSessionId();
        return sessions.Select(session => new ApplicationSessionSummary(
            session.SessionId,
            session.UserId,
            session.UserName,
            session.Role,
            session.CreatedAtUtc,
            session.LastSeenAtUtc,
            session.ExpiresAtUtc,
            session.RevokedAtUtc,
            session.RevokedReason,
            session.IpAddress,
            session.UserAgent,
            session.Persistent,
            currentSessionId.HasValue && session.SessionId == currentSessionId.Value)).ToArray();
    }

    private Guid RequireHttpUserId()
    {
        var principal = httpContextAccessor.HttpContext?.User;
        if (principal?.Identity?.IsAuthenticated != true ||
            !Guid.TryParse(principal.FindFirstValue(ClaimTypes.NameIdentifier), out var userId))
            throw new UnauthorizedAccessException("Authenticated HTTP user identity is required.");
        return userId;
    }

    private Guid? GetCurrentSessionId()
    {
        var value = httpContextAccessor.HttpContext?.User.FindFirstValue(ApplicationSessionStore.SessionIdClaimType);
        return Guid.TryParse(value, out var sessionId) ? sessionId : null;
    }
}

public sealed record ApplicationSessionSummary(
    Guid SessionId,
    Guid UserId,
    string UserName,
    string Role,
    DateTime CreatedAtUtc,
    DateTime LastSeenAtUtc,
    DateTime ExpiresAtUtc,
    DateTime? RevokedAtUtc,
    string RevokedReason,
    string IpAddress,
    string UserAgent,
    bool Persistent,
    bool IsCurrent);

public sealed record SessionAdministrationResult(bool IsSuccess, string Message, Guid SessionId)
{
    public static SessionAdministrationResult Failed(string message) => new(false, message, Guid.Empty);
    public static SessionAdministrationResult Succeeded(Guid sessionId) => new(true, string.Empty, sessionId);
}
