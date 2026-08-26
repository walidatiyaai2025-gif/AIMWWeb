using System.Security.Claims;
using System.Text.Json;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationSessionRequestValidator(AppDbContext dbContext)
{
    private const int CurrentSessionRegistryVersion = 1;
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly ApplicationSessionStore _sessions = new(dbContext);
    private readonly ApplicationRoleStore _roles = new(dbContext);

    public async Task<SessionRequestValidationResult> ValidateAsync(
        ClaimsPrincipal principal,
        CancellationToken cancellationToken = default)
    {
        if (!TryReadTrackedIdentity(principal, out var userId, out var sessionId))
            return SessionRequestValidationResult.Invalid(null, "Missing tracked session identity.");

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

        var roleResult = CompareRoleAndPermissions(principal, resolvedRole, await _roles.ResolvePermissionsAsync(resolvedRole, cancellationToken));
        if (!roleResult.IsValid)
            return SessionRequestValidationResult.Invalid(sessionId, roleResult.Reason);

        await _sessions.TouchAsync(sessionId, cancellationToken);
        return SessionRequestValidationResult.Valid(sessionId);
    }

    /// <summary>
    /// Revalidates a long-lived Interactive Server principal against durable session, account,
    /// role and permission truth without extending session activity. This path is synchronous on
    /// purpose: CurrentUserContext exposes synchronous authorization guards used by existing
    /// application services, and blocking on EF asynchronous continuations inside a Blazor circuit
    /// could deadlock the circuit synchronization context.
    /// </summary>
    public SessionRequestValidationResult ValidateForAuthorization(ClaimsPrincipal principal)
    {
        if (!TryReadTrackedIdentity(principal, out var userId, out var sessionId))
            return SessionRequestValidationResult.Invalid(null, "Missing tracked session identity.");

        var stored = ValidateTrackedSession(sessionId, userId);
        if (!stored.IsValid)
            return SessionRequestValidationResult.Invalid(sessionId, stored.Reason);

        var user = dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id == userId)
            .Select(x => new { x.IsActive, x.Role })
            .SingleOrDefault();
        if (user is null || !user.IsActive)
            return SessionRequestValidationResult.Invalid(sessionId, "Account is unavailable or inactive.");

        var resolvedRole = ResolveAssignableRoleName(user.Role);
        if (resolvedRole is null)
            return SessionRequestValidationResult.Invalid(sessionId, "Application role is unavailable or inactive.");

        var storedRolesJson = dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationRoleStore.SettingsKey)
            .Select(x => x.Value)
            .SingleOrDefault();
        var currentPermissions = ApplicationRoleStore.ResolvePermissions(resolvedRole, storedRolesJson);
        var roleResult = CompareRoleAndPermissions(principal, resolvedRole, currentPermissions);
        return roleResult.IsValid
            ? SessionRequestValidationResult.Valid(sessionId)
            : SessionRequestValidationResult.Invalid(sessionId, roleResult.Reason);
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

    private SessionValidationResult ValidateTrackedSession(Guid sessionId, Guid userId)
    {
        if (sessionId == Guid.Empty || userId == Guid.Empty)
            return SessionValidationResult.Invalid("Session identity is missing.");

        IReadOnlyList<ApplicationSessionRecord> records;
        try
        {
            var value = dbContext.ApplicationSettings.AsNoTracking()
                .Where(x => x.Key == ApplicationSessionStore.SettingsKey)
                .Select(x => x.Value)
                .SingleOrDefault();
            records = DeserializeSessions(value);
        }
        catch (InvalidOperationException)
        {
            return SessionValidationResult.Invalid("Session registry is invalid.");
        }

        var record = records.FirstOrDefault(x => x.SessionId == sessionId);
        if (record is null || record.UserId != userId)
            return SessionValidationResult.Invalid("Session was not found or does not belong to this account.");
        if (record.RevokedAtUtc.HasValue)
            return SessionValidationResult.Invalid("Session has been revoked.");
        if (record.ExpiresAtUtc <= DateTime.UtcNow)
            return SessionValidationResult.Invalid("Session has expired.");

        return SessionValidationResult.Valid(record);
    }

    private string? ResolveAssignableRoleName(string? role)
    {
        if (string.Equals(role?.Trim(), "Administrator", StringComparison.OrdinalIgnoreCase)) return "Administrator";
        if (string.Equals(role?.Trim(), "User", StringComparison.OrdinalIgnoreCase)) return "User";

        var normalized = ApplicationRoleStore.Normalize(role);
        if (normalized.Length == 0) return null;

        var value = dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == ApplicationRoleStore.SettingsKey)
            .Select(x => x.Value)
            .SingleOrDefault();
        if (string.IsNullOrWhiteSpace(value)) return null;

        try
        {
            return (JsonSerializer.Deserialize<List<CustomApplicationRole>>(value, JsonOptions) ?? [])
                .FirstOrDefault(candidate =>
                    candidate is not null &&
                    candidate.IsActive &&
                    !string.IsNullOrWhiteSpace(candidate.Name) &&
                    !ApplicationRoleStore.IsBuiltInRole(candidate.Name) &&
                    candidate.Permissions is not null &&
                    candidate.Permissions.All(ApplicationPermissionCatalog.IsCustomRoleAssignable) &&
                    ApplicationRoleStore.Normalize(candidate.Name) == normalized)?.Name;
        }
        catch (JsonException)
        {
            return null;
        }
    }

    private static SessionRequestValidationResult CompareRoleAndPermissions(
        ClaimsPrincipal principal,
        string resolvedRole,
        IReadOnlyList<string> currentPermissions)
    {
        var cookieRole = principal.FindFirstValue(ClaimTypes.Role) ?? string.Empty;
        var normalizedCurrentPermissions = currentPermissions
            .OrderBy(value => value, StringComparer.Ordinal)
            .ToArray();
        var cookiePermissions = principal.FindAll(ApplicationPermissionCatalog.ClaimType)
            .Select(claim => claim.Value)
            .Distinct(StringComparer.Ordinal)
            .OrderBy(value => value, StringComparer.Ordinal)
            .ToArray();

        if (!string.Equals(cookieRole, resolvedRole, StringComparison.Ordinal) ||
            !cookiePermissions.SequenceEqual(normalizedCurrentPermissions, StringComparer.Ordinal))
        {
            return SessionRequestValidationResult.Invalid(null, "Account role or permissions changed.");
        }

        return SessionRequestValidationResult.Valid(Guid.Empty);
    }

    private static bool TryReadTrackedIdentity(ClaimsPrincipal principal, out Guid userId, out Guid sessionId)
    {
        userId = Guid.Empty;
        sessionId = Guid.Empty;
        return principal.Identity?.IsAuthenticated == true &&
               Guid.TryParse(principal.FindFirstValue(ClaimTypes.NameIdentifier), out userId) &&
               Guid.TryParse(principal.FindFirstValue(ApplicationSessionStore.SessionIdClaimType), out sessionId);
    }

    private static IReadOnlyList<ApplicationSessionRecord> DeserializeSessions(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return [];

        try
        {
            var document = JsonSerializer.Deserialize<SessionRegistryDocument>(value, JsonOptions);
            if (document is null || document.Version != CurrentSessionRegistryVersion || document.Sessions is null)
                throw new InvalidOperationException("Session registry version is unsupported or incomplete.");
            if (document.Sessions.Any(record =>
                    record.SessionId == Guid.Empty ||
                    record.UserId == Guid.Empty ||
                    record.CreatedAtUtc > record.ExpiresAtUtc))
            {
                throw new InvalidOperationException("Session registry contains invalid records.");
            }
            if (document.Sessions.GroupBy(record => record.SessionId).Any(group => group.Count() > 1))
                throw new InvalidOperationException("Session registry contains duplicate session identifiers.");
            return document.Sessions;
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("Session registry is not valid JSON.", ex);
        }
    }

    private sealed record SessionRegistryDocument(int Version, IReadOnlyList<ApplicationSessionRecord> Sessions);
}

public sealed record SessionRequestValidationResult(bool IsValid, Guid? SessionId, string Reason)
{
    public static SessionRequestValidationResult Valid(Guid sessionId) => new(true, sessionId, string.Empty);
    public static SessionRequestValidationResult Invalid(Guid? sessionId, string reason) => new(false, sessionId, reason);
}
