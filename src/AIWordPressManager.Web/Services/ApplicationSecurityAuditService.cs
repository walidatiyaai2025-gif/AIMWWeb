namespace AIWordPressManager.Web.Services;

/// <summary>
/// Server-owned security audit boundary. Callers provide only event semantics; actor and request
/// context are resolved from trusted server state. Reading the security trail is administrator-only.
/// </summary>
public sealed class ApplicationSecurityAuditService(
    AIWordPressManager.Persistence.AppDbContext dbContext,
    CurrentUserContext currentUser,
    IHttpContextAccessor? httpContextAccessor = null,
    ApplicationSecurityAuditStore? store = null)
{
    private readonly ApplicationSecurityAuditStore _store = store ?? new ApplicationSecurityAuditStore(dbContext);

    public Task<SecurityAuditRecord> RecordCurrentAsync(
        string category,
        string action,
        string outcome,
        string targetType,
        string? targetId = null,
        string? targetDisplayName = null,
        IReadOnlyDictionary<string, string>? metadata = null,
        CancellationToken cancellationToken = default)
    {
        currentUser.TryGetUserId(out var actorId);
        var context = httpContextAccessor?.HttpContext;
        return _store.AppendAsync(new SecurityAuditEvent(
            category,
            action,
            outcome,
            actorId == Guid.Empty ? null : actorId,
            currentUser.UserName,
            targetType,
            targetId,
            targetDisplayName,
            context?.TraceIdentifier,
            context?.Connection.RemoteIpAddress?.ToString(),
            context?.Request.Headers.UserAgent.ToString(),
            metadata), cancellationToken);
    }

    public Task<SecurityAuditRecord> RecordAuthenticationAsync(
        HttpContext context,
        Guid? userId,
        string? userName,
        string outcome,
        string reason,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(context);
        return _store.AppendAsync(new SecurityAuditEvent(
            "Authentication",
            "SignIn",
            outcome,
            userId,
            userName,
            "ApplicationUser",
            userId?.ToString("D"),
            userName,
            context.TraceIdentifier,
            context.Connection.RemoteIpAddress?.ToString(),
            context.Request.Headers.UserAgent.ToString(),
            new Dictionary<string, string> { ["reason"] = reason }), cancellationToken);
    }

    public Task<SecurityAuditRecord> RecordLogoutAsync(
        HttpContext context,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(context);
        var userId = currentUser.TryGetUserId(out var resolvedUserId) ? resolvedUserId : (Guid?)null;
        return _store.AppendAsync(new SecurityAuditEvent(
            "Authentication",
            "SignOut",
            "Succeeded",
            userId,
            currentUser.UserName,
            "ApplicationUser",
            userId?.ToString("D"),
            currentUser.UserName,
            context.TraceIdentifier,
            context.Connection.RemoteIpAddress?.ToString(),
            context.Request.Headers.UserAgent.ToString()), cancellationToken);
    }

    public Task<IReadOnlyList<SecurityAuditRecord>> ListAsync(
        SecurityAuditQuery? query = null,
        CancellationToken cancellationToken = default)
    {
        currentUser.RequirePermission(ApplicationPermissionCatalog.SettingsManage);
        return _store.ListAsync(query, cancellationToken);
    }
}
