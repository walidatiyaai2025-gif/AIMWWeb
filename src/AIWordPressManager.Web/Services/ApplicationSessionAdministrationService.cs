namespace AIWordPressManager.Web.Services;

public sealed class ApplicationSessionAdministrationService(
    CurrentUserContext currentUser,
    ApplicationSessionStore store)
{
    public async Task<IReadOnlyList<ApplicationSessionView>> ListAllAsync(
        bool activeOnly = false,
        int take = 250,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        var currentSessionId = currentUser.SessionId;
        return (await store.ListAsync(activeOnly: activeOnly, take: take, cancellationToken: cancellationToken))
            .Select(x => ApplicationSessionView.From(x, currentSessionId))
            .ToArray();
    }

    public async Task<IReadOnlyList<ApplicationSessionView>> ListOwnAsync(
        bool activeOnly = false,
        int take = 100,
        CancellationToken cancellationToken = default)
    {
        var userId = currentUser.RequireUserId();
        var currentSessionId = currentUser.SessionId;
        return (await store.ListAsync(userId, activeOnly, take, cancellationToken))
            .Select(x => ApplicationSessionView.From(x, currentSessionId))
            .ToArray();
    }

    public async Task<SessionAdministrationResult> RevokeAsync(
        string sessionId,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.UsersManage);
        if (!ApplicationSessionStore.TryNormalizeSessionId(sessionId, out var normalized))
            return SessionAdministrationResult.Failed("Session identifier is invalid.");

        var session = await store.GetAsync(normalized, cancellationToken);
        if (session is null) return SessionAdministrationResult.Failed("Session was not found.");
        if (session.RevokedAtUtc.HasValue) return SessionAdministrationResult.Failed("Session is already revoked.");

        var revoked = await store.RevokeAsync(normalized, "Revoked by administrator", cancellationToken);
        return revoked
            ? SessionAdministrationResult.Succeeded(1)
            : SessionAdministrationResult.Failed("Session could not be revoked.");
    }

    public async Task<SessionAdministrationResult> RevokeOwnAsync(
        string sessionId,
        CancellationToken cancellationToken = default)
    {
        var userId = currentUser.RequireUserId();
        if (!ApplicationSessionStore.TryNormalizeSessionId(sessionId, out var normalized))
            return SessionAdministrationResult.Failed("Session identifier is invalid.");

        var session = await store.GetAsync(normalized, cancellationToken);
        if (session is null || session.UserId != userId)
            return SessionAdministrationResult.Failed("Session was not found for this account.");
        if (session.RevokedAtUtc.HasValue)
            return SessionAdministrationResult.Failed("Session is already revoked.");

        var revoked = await store.RevokeAsync(normalized, "Revoked by account owner", cancellationToken);
        return revoked
            ? SessionAdministrationResult.Succeeded(1)
            : SessionAdministrationResult.Failed("Session could not be revoked.");
    }

    public async Task<SessionAdministrationResult> RevokeOtherOwnSessionsAsync(
        CancellationToken cancellationToken = default)
    {
        var userId = currentUser.RequireUserId();
        var currentSessionId = currentUser.SessionId;
        var revoked = await store.RevokeUserSessionsAsync(
            userId,
            "Revoked by account owner",
            currentSessionId,
            cancellationToken);
        return SessionAdministrationResult.Succeeded(revoked);
    }
}

public sealed record ApplicationSessionView(
    string SessionId,
    Guid UserId,
    string UserName,
    string Role,
    DateTime CreatedAtUtc,
    DateTime LastSeenAtUtc,
    DateTime ExpiresAtUtc,
    bool RememberMe,
    string IpAddress,
    string UserAgent,
    DateTime? RevokedAtUtc,
    string? RevokedReason,
    bool IsCurrent,
    bool IsActive)
{
    public static ApplicationSessionView From(ApplicationSessionRecord session, string currentSessionId)
    {
        var isCurrent = ApplicationSessionStore.TryNormalizeSessionId(currentSessionId, out var normalized) &&
                        string.Equals(session.SessionId, normalized, StringComparison.Ordinal);
        return new ApplicationSessionView(
            session.SessionId,
            session.UserId,
            session.UserName,
            session.Role,
            session.CreatedAtUtc,
            session.LastSeenAtUtc,
            session.ExpiresAtUtc,
            session.RememberMe,
            session.IpAddress,
            session.UserAgent,
            session.RevokedAtUtc,
            session.RevokedReason,
            isCurrent,
            session.IsActive(DateTime.UtcNow));
    }
}

public sealed record SessionAdministrationResult(bool IsSuccess, string Message, int RevokedCount)
{
    public static SessionAdministrationResult Failed(string message) => new(false, message, 0);
    public static SessionAdministrationResult Succeeded(int count) => new(true, string.Empty, count);
}
