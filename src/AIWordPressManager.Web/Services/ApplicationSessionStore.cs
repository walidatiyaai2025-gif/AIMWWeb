using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class ApplicationSessionStore(AppDbContext dbContext)
{
    public const string ClaimType = "aiwm:session_id";
    public const string SettingsPrefix = "Security.Session.";
    public static readonly TimeSpan StandardLifetime = TimeSpan.FromHours(8);
    public static readonly TimeSpan PersistentLifetime = TimeSpan.FromDays(14);
    private static readonly TimeSpan TouchInterval = TimeSpan.FromMinutes(5);
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<ApplicationSessionRecord> CreateAsync(
        Guid userId,
        string userName,
        string role,
        bool rememberMe,
        HttpContext context,
        CancellationToken cancellationToken = default)
    {
        if (userId == Guid.Empty) throw new ArgumentException("User id is required.", nameof(userId));

        var now = DateTime.UtcNow;
        var session = new ApplicationSessionRecord(
            Guid.NewGuid().ToString("N"),
            userId,
            userName ?? string.Empty,
            role ?? string.Empty,
            now,
            now,
            now.Add(rememberMe ? PersistentLifetime : StandardLifetime),
            rememberMe,
            context.Connection.RemoteIpAddress?.ToString() ?? string.Empty,
            context.Request.Headers.UserAgent.ToString(),
            null,
            null);

        dbContext.ApplicationSettings.Add(new ApplicationSetting(Key(session.SessionId), Serialize(session), now));
        await dbContext.SaveChangesAsync(cancellationToken);
        return session;
    }

    public async Task<ApplicationSessionRecord?> GetAsync(string? sessionId, CancellationToken cancellationToken = default)
    {
        if (!TryNormalizeSessionId(sessionId, out var normalized)) return null;

        var value = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == Key(normalized))
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);

        return Deserialize(value);
    }

    public async Task<ApplicationSessionValidationResult> ValidateAsync(
        string? sessionId,
        Guid userId,
        CancellationToken cancellationToken = default)
    {
        if (!TryNormalizeSessionId(sessionId, out var normalized) || userId == Guid.Empty)
            return ApplicationSessionValidationResult.Invalid("Missing or invalid session identity.");

        var setting = await dbContext.ApplicationSettings
            .SingleOrDefaultAsync(x => x.Key == Key(normalized), cancellationToken);
        if (setting is null) return ApplicationSessionValidationResult.Invalid("Session was not found.");

        var session = Deserialize(setting.Value);
        if (session is null || !string.Equals(session.SessionId, normalized, StringComparison.Ordinal))
            return ApplicationSessionValidationResult.Invalid("Session data is invalid.");
        if (session.UserId != userId)
            return ApplicationSessionValidationResult.Invalid("Session user does not match the authenticated identity.");
        if (session.RevokedAtUtc.HasValue)
            return ApplicationSessionValidationResult.Invalid("Session has been revoked.");

        var now = DateTime.UtcNow;
        if (session.ExpiresAtUtc <= now)
            return ApplicationSessionValidationResult.Invalid("Session has expired.");

        var user = await dbContext.AuthUsers.AsNoTracking()
            .Where(x => x.Id == userId)
            .Select(x => new { x.IsActive, x.Role, x.NormalizedUserName })
            .SingleOrDefaultAsync(cancellationToken);
        if (user is null || !user.IsActive)
            return ApplicationSessionValidationResult.Invalid("Application account is unavailable.");
        if (!string.Equals(user.Role, session.Role, StringComparison.OrdinalIgnoreCase))
            return ApplicationSessionValidationResult.Invalid("Session role is stale.");

        if (now - session.LastSeenAtUtc >= TouchInterval)
        {
            session = session with { LastSeenAtUtc = now };
            setting.SetValue(setting.Key, Serialize(session), now);
            await dbContext.SaveChangesAsync(cancellationToken);
        }

        return ApplicationSessionValidationResult.Valid(session);
    }

    public async Task<IReadOnlyList<ApplicationSessionRecord>> ListAsync(
        Guid? userId = null,
        bool activeOnly = false,
        int take = 250,
        CancellationToken cancellationToken = default)
    {
        take = Math.Clamp(take, 1, 1000);
        var query = dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key.StartsWith(SettingsPrefix));

        var values = await query
            .OrderByDescending(x => x.UpdatedAtUtc)
            .Take(Math.Min(take * 3, 3000))
            .Select(x => x.Value)
            .ToListAsync(cancellationToken);

        var now = DateTime.UtcNow;
        return values
            .Select(Deserialize)
            .Where(x => x is not null)
            .Select(x => x!)
            .Where(x => !userId.HasValue || x.UserId == userId.Value)
            .Where(x => !activeOnly || x.IsActive(now))
            .OrderByDescending(x => x.LastSeenAtUtc)
            .Take(take)
            .ToArray();
    }

    public async Task<bool> RevokeAsync(
        string? sessionId,
        string reason,
        CancellationToken cancellationToken = default)
    {
        if (!TryNormalizeSessionId(sessionId, out var normalized)) return false;

        var setting = await dbContext.ApplicationSettings
            .SingleOrDefaultAsync(x => x.Key == Key(normalized), cancellationToken);
        if (setting is null) return false;

        var session = Deserialize(setting.Value);
        if (session is null || session.RevokedAtUtc.HasValue) return false;

        var now = DateTime.UtcNow;
        session = session with
        {
            RevokedAtUtc = now,
            RevokedReason = string.IsNullOrWhiteSpace(reason) ? "Revoked" : reason.Trim()
        };
        setting.SetValue(setting.Key, Serialize(session), now);
        await dbContext.SaveChangesAsync(cancellationToken);
        return true;
    }

    public async Task<int> RevokeUserSessionsAsync(
        Guid userId,
        string reason,
        string? exceptSessionId = null,
        CancellationToken cancellationToken = default)
    {
        if (userId == Guid.Empty) return 0;
        TryNormalizeSessionId(exceptSessionId, out var normalizedException);

        var settings = await dbContext.ApplicationSettings
            .Where(x => x.Key.StartsWith(SettingsPrefix))
            .ToListAsync(cancellationToken);
        var now = DateTime.UtcNow;
        var revoked = 0;

        foreach (var setting in settings)
        {
            var session = Deserialize(setting.Value);
            if (session is null || session.UserId != userId || session.RevokedAtUtc.HasValue || session.ExpiresAtUtc <= now)
                continue;
            if (!string.IsNullOrWhiteSpace(normalizedException) && string.Equals(session.SessionId, normalizedException, StringComparison.Ordinal))
                continue;

            var updated = session with
            {
                RevokedAtUtc = now,
                RevokedReason = string.IsNullOrWhiteSpace(reason) ? "Revoked" : reason.Trim()
            };
            setting.SetValue(setting.Key, Serialize(updated), now);
            revoked++;
        }

        if (revoked > 0) await dbContext.SaveChangesAsync(cancellationToken);
        return revoked;
    }

    public static bool TryNormalizeSessionId(string? value, out string normalized)
    {
        normalized = string.Empty;
        if (!Guid.TryParseExact(value, "N", out var parsed) && !Guid.TryParse(value, out parsed)) return false;
        normalized = parsed.ToString("N");
        return true;
    }

    private static string Key(string sessionId) => SettingsPrefix + sessionId;
    private static string Serialize(ApplicationSessionRecord session) => JsonSerializer.Serialize(session, JsonOptions);

    private static ApplicationSessionRecord? Deserialize(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        try
        {
            var session = JsonSerializer.Deserialize<ApplicationSessionRecord>(value, JsonOptions);
            return session is not null && TryNormalizeSessionId(session.SessionId, out var normalized)
                ? session with { SessionId = normalized }
                : null;
        }
        catch (JsonException)
        {
            return null;
        }
    }
}

public sealed record ApplicationSessionRecord(
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
    string? RevokedReason)
{
    public bool IsActive(DateTime utcNow) => !RevokedAtUtc.HasValue && ExpiresAtUtc > utcNow;
}

public sealed record ApplicationSessionValidationResult(bool IsValid, string Message, ApplicationSessionRecord? Session)
{
    public static ApplicationSessionValidationResult Invalid(string message) => new(false, message, null);
    public static ApplicationSessionValidationResult Valid(ApplicationSessionRecord session) => new(true, string.Empty, session);
}
