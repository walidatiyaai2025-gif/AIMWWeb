using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Provider-neutral server-side session registry stored in the existing ApplicationSettings table.
/// Every mutation uses the entity concurrency token and retries so concurrent sign-ins/revocations
/// cannot silently overwrite each other.
/// </summary>
public sealed class ApplicationSessionStore(AppDbContext dbContext)
{
    public const string SettingsKey = "Security.Sessions.v1";
    public const string SessionIdClaimType = "aiwm:session_id";
    private const int CurrentVersion = 1;
    private const int MaxRecords = 2_000;
    private static readonly TimeSpan RetentionAfterInactive = TimeSpan.FromDays(30);
    private static readonly TimeSpan SeenWriteInterval = TimeSpan.FromMinutes(5);
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public async Task<ApplicationSessionRecord> CreateAsync(
        Guid userId,
        string userName,
        string role,
        string? ipAddress,
        string? userAgent,
        bool persistent,
        CancellationToken cancellationToken = default)
    {
        if (userId == Guid.Empty) throw new ArgumentException("User ID is required.", nameof(userId));
        var now = DateTime.UtcNow;
        var record = new ApplicationSessionRecord(
            Guid.NewGuid(),
            userId,
            Clean(userName, 64),
            Clean(role, 64),
            now,
            now,
            now.Add(persistent ? TimeSpan.FromDays(14) : TimeSpan.FromHours(8)),
            null,
            string.Empty,
            Clean(ipAddress, 64),
            Clean(userAgent, 300),
            persistent);

        await MutateAsync(records => records.Add(record), cancellationToken);
        return record;
    }

    public async Task<SessionValidationResult> ValidateAsync(
        Guid sessionId,
        Guid userId,
        CancellationToken cancellationToken = default)
    {
        if (sessionId == Guid.Empty || userId == Guid.Empty)
            return SessionValidationResult.Invalid("Session identity is missing.");

        IReadOnlyList<ApplicationSessionRecord> records;
        try
        {
            records = await ReadAsync(cancellationToken);
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

    public async Task TouchAsync(Guid sessionId, CancellationToken cancellationToken = default)
    {
        var now = DateTime.UtcNow;
        var current = await TryGetAsync(sessionId, cancellationToken);
        if (current is null || current.RevokedAtUtc.HasValue || current.ExpiresAtUtc <= now || now - current.LastSeenAtUtc < SeenWriteInterval)
            return;

        await MutateAsync(records =>
        {
            var index = records.FindIndex(x => x.SessionId == sessionId);
            if (index < 0) return;
            var record = records[index];
            if (!record.RevokedAtUtc.HasValue && record.ExpiresAtUtc > now)
                records[index] = record with { LastSeenAtUtc = now };
        }, cancellationToken);
    }

    public Task RevokeAsync(Guid sessionId, string reason, CancellationToken cancellationToken = default) =>
        RevokeWhereAsync(x => x.SessionId == sessionId, reason, cancellationToken);

    public Task RevokeUserAsync(Guid userId, string reason, CancellationToken cancellationToken = default) =>
        RevokeWhereAsync(x => x.UserId == userId, reason, cancellationToken);

    public Task RevokeRoleAsync(string role, string reason, CancellationToken cancellationToken = default)
    {
        var normalized = ApplicationRoleStore.Normalize(role);
        if (normalized.Length == 0) return Task.CompletedTask;
        return RevokeWhereAsync(x => ApplicationRoleStore.Normalize(x.Role) == normalized, reason, cancellationToken);
    }

    public async Task<IReadOnlyList<ApplicationSessionRecord>> ListAsync(
        Guid? userId = null,
        bool includeInactive = false,
        CancellationToken cancellationToken = default)
    {
        var now = DateTime.UtcNow;
        var records = await ReadAsync(cancellationToken);
        return records
            .Where(x => !userId.HasValue || x.UserId == userId.Value)
            .Where(x => includeInactive || (!x.RevokedAtUtc.HasValue && x.ExpiresAtUtc > now))
            .OrderByDescending(x => x.LastSeenAtUtc)
            .ThenByDescending(x => x.CreatedAtUtc)
            .ToArray();
    }

    public async Task<ApplicationSessionRecord?> TryGetAsync(Guid sessionId, CancellationToken cancellationToken = default)
    {
        if (sessionId == Guid.Empty) return null;
        try
        {
            return (await ReadAsync(cancellationToken)).FirstOrDefault(x => x.SessionId == sessionId);
        }
        catch (InvalidOperationException)
        {
            return null;
        }
    }

    private async Task RevokeWhereAsync(
        Func<ApplicationSessionRecord, bool> predicate,
        string reason,
        CancellationToken cancellationToken)
    {
        var now = DateTime.UtcNow;
        var cleanReason = Clean(reason, 160);
        await MutateAsync(records =>
        {
            for (var index = 0; index < records.Count; index++)
            {
                var record = records[index];
                if (predicate(record) && !record.RevokedAtUtc.HasValue && record.ExpiresAtUtc > now)
                    records[index] = record with { RevokedAtUtc = now, RevokedReason = cleanReason };
            }
        }, cancellationToken);
    }

    private async Task<IReadOnlyList<ApplicationSessionRecord>> ReadAsync(CancellationToken cancellationToken)
    {
        var value = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == SettingsKey)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);
        return Deserialize(value);
    }

    private async Task MutateAsync(Action<List<ApplicationSessionRecord>> mutation, CancellationToken cancellationToken)
    {
        for (var attempt = 0; attempt < 3; attempt++)
        {
            var setting = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == SettingsKey, cancellationToken);
            List<ApplicationSessionRecord> records;
            try
            {
                records = Deserialize(setting?.Value).ToList();
            }
            catch (InvalidOperationException)
            {
                if (setting is not null) dbContext.Entry(setting).State = EntityState.Detached;
                throw;
            }

            mutation(records);
            Prune(records, DateTime.UtcNow);
            var json = JsonSerializer.Serialize(new SessionRegistryDocument(CurrentVersion, records), JsonOptions);
            var now = DateTime.UtcNow;

            if (setting is null)
            {
                setting = new ApplicationSetting(SettingsKey, json, now);
                dbContext.ApplicationSettings.Add(setting);
            }
            else
            {
                setting.SetValue(SettingsKey, json, now);
            }

            try
            {
                await dbContext.SaveChangesAsync(cancellationToken);
                return;
            }
            catch (DbUpdateConcurrencyException) when (attempt < 2)
            {
                dbContext.Entry(setting).State = EntityState.Detached;
            }
            catch (DbUpdateException) when (attempt < 2)
            {
                dbContext.Entry(setting).State = EntityState.Detached;
            }
        }

        throw new DbUpdateConcurrencyException("Session registry changed concurrently. Retry the operation.");
    }

    private static IReadOnlyList<ApplicationSessionRecord> Deserialize(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return [];
        try
        {
            var document = JsonSerializer.Deserialize<SessionRegistryDocument>(value, JsonOptions);
            if (document is null || document.Version != CurrentVersion || document.Sessions is null)
                throw new InvalidOperationException("Session registry version is unsupported or incomplete.");
            if (document.Sessions.Any(record => record.SessionId == Guid.Empty || record.UserId == Guid.Empty || record.CreatedAtUtc > record.ExpiresAtUtc))
                throw new InvalidOperationException("Session registry contains invalid records.");
            if (document.Sessions.GroupBy(record => record.SessionId).Any(group => group.Count() > 1))
                throw new InvalidOperationException("Session registry contains duplicate session identifiers.");
            return document.Sessions;
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("Session registry is not valid JSON.", ex);
        }
    }

    private static void Prune(List<ApplicationSessionRecord> records, DateTime utcNow)
    {
        records.RemoveAll(record =>
            (record.RevokedAtUtc.HasValue && utcNow - record.RevokedAtUtc.Value > RetentionAfterInactive) ||
            (record.ExpiresAtUtc < utcNow - RetentionAfterInactive));

        if (records.Count <= MaxRecords) return;
        var keep = records
            .OrderByDescending(record => !record.RevokedAtUtc.HasValue && record.ExpiresAtUtc > utcNow)
            .ThenByDescending(record => record.LastSeenAtUtc)
            .Take(MaxRecords)
            .Select(record => record.SessionId)
            .ToHashSet();
        records.RemoveAll(record => !keep.Contains(record.SessionId));
    }

    private static string Clean(string? value, int maxLength)
    {
        var clean = (value ?? string.Empty).Trim();
        return clean.Length <= maxLength ? clean : clean[..maxLength];
    }

    private sealed record SessionRegistryDocument(int Version, IReadOnlyList<ApplicationSessionRecord> Sessions);
}

public sealed record ApplicationSessionRecord(
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
    bool Persistent);

public sealed record SessionValidationResult(bool IsValid, string Reason, ApplicationSessionRecord? Session)
{
    public static SessionValidationResult Valid(ApplicationSessionRecord session) => new(true, string.Empty, session);
    public static SessionValidationResult Invalid(string reason) => new(false, reason, null);
}