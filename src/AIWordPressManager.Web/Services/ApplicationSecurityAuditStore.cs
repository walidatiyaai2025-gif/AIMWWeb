using System.Text.Json;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

/// <summary>
/// Provider-neutral append-only security audit registry stored in the existing ApplicationSettings table.
/// The store fails closed when persisted data is malformed and sanitizes bounded metadata before persistence.
/// </summary>
public sealed class ApplicationSecurityAuditStore(AppDbContext dbContext)
{
    public const string SettingsKey = "Security.Audit.v1";
    private const int CurrentVersion = 1;
    private const int MaxRecords = 10_000;
    private const int MaxMetadataEntries = 24;
    private static readonly TimeSpan Retention = TimeSpan.FromDays(365);
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private static readonly SemaphoreSlim MutationGate = new(1, 1);
    private static readonly string[] SensitiveMetadataFragments =
    [
        "password", "passwd", "pwd", "secret", "token", "apikey", "api-key", "api_key",
        "authorization", "cookie", "credential", "connectionstring", "connection-string", "hash"
    ];

    public async Task<SecurityAuditRecord> AppendAsync(
        SecurityAuditEvent auditEvent,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(auditEvent);
        var record = Normalize(auditEvent, DateTime.UtcNow);
        await MutateAsync(records => records.Add(record), cancellationToken);
        return record;
    }

    public async Task<IReadOnlyList<SecurityAuditRecord>> ListAsync(
        SecurityAuditQuery? query = null,
        CancellationToken cancellationToken = default)
    {
        query ??= new SecurityAuditQuery();
        var records = await ReadAsync(cancellationToken);
        var filtered = records.AsEnumerable();

        if (!string.IsNullOrWhiteSpace(query.Category))
            filtered = filtered.Where(x => string.Equals(x.Category, query.Category.Trim(), StringComparison.OrdinalIgnoreCase));
        if (!string.IsNullOrWhiteSpace(query.Action))
            filtered = filtered.Where(x => string.Equals(x.Action, query.Action.Trim(), StringComparison.OrdinalIgnoreCase));
        if (!string.IsNullOrWhiteSpace(query.Outcome))
            filtered = filtered.Where(x => string.Equals(x.Outcome, query.Outcome.Trim(), StringComparison.OrdinalIgnoreCase));
        if (query.ActorUserId.HasValue)
            filtered = filtered.Where(x => x.ActorUserId == query.ActorUserId);
        if (!string.IsNullOrWhiteSpace(query.Search))
        {
            var term = query.Search.Trim();
            filtered = filtered.Where(x =>
                x.ActorUserName.Contains(term, StringComparison.OrdinalIgnoreCase) ||
                x.TargetType.Contains(term, StringComparison.OrdinalIgnoreCase) ||
                x.TargetId.Contains(term, StringComparison.OrdinalIgnoreCase) ||
                x.TargetDisplayName.Contains(term, StringComparison.OrdinalIgnoreCase) ||
                x.Action.Contains(term, StringComparison.OrdinalIgnoreCase));
        }
        if (query.FromUtc.HasValue)
            filtered = filtered.Where(x => x.OccurredAtUtc >= query.FromUtc.Value);
        if (query.ToUtc.HasValue)
            filtered = filtered.Where(x => x.OccurredAtUtc <= query.ToUtc.Value);

        var take = Math.Clamp(query.Take, 1, 500);
        return filtered
            .OrderByDescending(x => x.OccurredAtUtc)
            .ThenByDescending(x => x.EventId)
            .Take(take)
            .ToArray();
    }

    private async Task<IReadOnlyList<SecurityAuditRecord>> ReadAsync(CancellationToken cancellationToken)
    {
        var value = await dbContext.ApplicationSettings.AsNoTracking()
            .Where(x => x.Key == SettingsKey)
            .Select(x => x.Value)
            .SingleOrDefaultAsync(cancellationToken);
        return Deserialize(value);
    }

    private async Task MutateAsync(Action<List<SecurityAuditRecord>> mutation, CancellationToken cancellationToken)
    {
        await MutationGate.WaitAsync(cancellationToken);
        try
        {
            for (var attempt = 0; attempt < 4; attempt++)
            {
                var setting = await dbContext.ApplicationSettings.SingleOrDefaultAsync(x => x.Key == SettingsKey, cancellationToken);
                List<SecurityAuditRecord> records;
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
                var json = JsonSerializer.Serialize(new SecurityAuditRegistryDocument(CurrentVersion, records), JsonOptions);
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
                catch (DbUpdateConcurrencyException) when (attempt < 3)
                {
                    dbContext.Entry(setting).State = EntityState.Detached;
                }
                catch (DbUpdateException) when (attempt < 3)
                {
                    dbContext.Entry(setting).State = EntityState.Detached;
                }
            }
        }
        finally
        {
            MutationGate.Release();
        }

        throw new DbUpdateConcurrencyException("Security audit registry changed concurrently. Retry the operation.");
    }

    private static IReadOnlyList<SecurityAuditRecord> Deserialize(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return [];

        try
        {
            var document = JsonSerializer.Deserialize<SecurityAuditRegistryDocument>(value, JsonOptions);
            if (document is null || document.Version != CurrentVersion || document.Events is null)
                throw new InvalidOperationException("Security audit registry version is unsupported or incomplete.");
            if (document.Events.Any(IsInvalid))
                throw new InvalidOperationException("Security audit registry contains invalid records.");
            if (document.Events.GroupBy(x => x.EventId).Any(group => group.Count() > 1))
                throw new InvalidOperationException("Security audit registry contains duplicate event identifiers.");
            return document.Events;
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("Security audit registry is not valid JSON.", ex);
        }
    }

    private static bool IsInvalid(SecurityAuditRecord record) =>
        record.EventId == Guid.Empty ||
        record.OccurredAtUtc == default ||
        string.IsNullOrWhiteSpace(record.Category) ||
        string.IsNullOrWhiteSpace(record.Action) ||
        string.IsNullOrWhiteSpace(record.Outcome) ||
        record.Metadata is null ||
        record.Metadata.Count > MaxMetadataEntries ||
        record.Metadata.Any(pair => IsSensitiveMetadataKey(pair.Key));

    private static SecurityAuditRecord Normalize(SecurityAuditEvent auditEvent, DateTime occurredAtUtc) =>
        new(
            Guid.NewGuid(),
            occurredAtUtc,
            CleanRequired(auditEvent.Category, 64, nameof(auditEvent.Category)),
            CleanRequired(auditEvent.Action, 96, nameof(auditEvent.Action)),
            CleanRequired(auditEvent.Outcome, 32, nameof(auditEvent.Outcome)),
            auditEvent.ActorUserId,
            Clean(auditEvent.ActorUserName, 64),
            CleanRequired(auditEvent.TargetType, 64, nameof(auditEvent.TargetType)),
            Clean(auditEvent.TargetId, 128),
            Clean(auditEvent.TargetDisplayName, 160),
            Clean(auditEvent.CorrelationId, 128),
            Clean(auditEvent.IpAddress, 64),
            Clean(auditEvent.UserAgent, 300),
            SanitizeMetadata(auditEvent.Metadata));

    private static IReadOnlyDictionary<string, string> SanitizeMetadata(IReadOnlyDictionary<string, string>? metadata)
    {
        var sanitized = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        if (metadata is null) return sanitized;

        foreach (var pair in metadata)
        {
            if (sanitized.Count >= MaxMetadataEntries) break;
            if (string.IsNullOrWhiteSpace(pair.Key) || IsSensitiveMetadataKey(pair.Key)) continue;
            var key = Clean(pair.Key, 64);
            if (key.Length == 0 || sanitized.ContainsKey(key)) continue;
            sanitized[key] = Clean(pair.Value, 300);
        }

        return sanitized;
    }

    private static bool IsSensitiveMetadataKey(string? key)
    {
        var normalized = (key ?? string.Empty)
            .Replace(" ", string.Empty, StringComparison.Ordinal)
            .ToLowerInvariant();
        return SensitiveMetadataFragments.Any(fragment => normalized.Contains(fragment, StringComparison.Ordinal));
    }

    private static void Prune(List<SecurityAuditRecord> records, DateTime utcNow)
    {
        records.RemoveAll(record => utcNow - record.OccurredAtUtc > Retention);
        if (records.Count <= MaxRecords) return;

        var keep = records
            .OrderByDescending(record => record.OccurredAtUtc)
            .ThenByDescending(record => record.EventId)
            .Take(MaxRecords)
            .Select(record => record.EventId)
            .ToHashSet();
        records.RemoveAll(record => !keep.Contains(record.EventId));
    }

    private static string CleanRequired(string? value, int maxLength, string parameterName)
    {
        var clean = Clean(value, maxLength);
        if (clean.Length == 0) throw new ArgumentException("A non-empty audit value is required.", parameterName);
        return clean;
    }

    private static string Clean(string? value, int maxLength)
    {
        var clean = (value ?? string.Empty).Trim();
        return clean.Length <= maxLength ? clean : clean[..maxLength];
    }

    private sealed record SecurityAuditRegistryDocument(int Version, IReadOnlyList<SecurityAuditRecord> Events);
}

public sealed record SecurityAuditEvent(
    string Category,
    string Action,
    string Outcome,
    Guid? ActorUserId,
    string? ActorUserName,
    string TargetType,
    string? TargetId = null,
    string? TargetDisplayName = null,
    string? CorrelationId = null,
    string? IpAddress = null,
    string? UserAgent = null,
    IReadOnlyDictionary<string, string>? Metadata = null);

public sealed record SecurityAuditRecord(
    Guid EventId,
    DateTime OccurredAtUtc,
    string Category,
    string Action,
    string Outcome,
    Guid? ActorUserId,
    string ActorUserName,
    string TargetType,
    string TargetId,
    string TargetDisplayName,
    string CorrelationId,
    string IpAddress,
    string UserAgent,
    IReadOnlyDictionary<string, string> Metadata);

public sealed record SecurityAuditQuery(
    string? Category = null,
    string? Action = null,
    string? Outcome = null,
    Guid? ActorUserId = null,
    string? Search = null,
    DateTime? FromUtc = null,
    DateTime? ToUtc = null,
    int Take = 200);
