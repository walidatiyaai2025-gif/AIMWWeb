using System.Text;
using System.Text.Json;
using AIWordPressManager.Application.Abstractions;
using AIWordPressManager.Application.Abstractions.AI;
using Microsoft.Extensions.Logging;

namespace AIWordPressManager.Infrastructure.AI;

public sealed class PersistentAIUsageLog : IAIUsageLog
{
    public const int MaxEntries = 10_000;
    private const int SchemaVersion = 1;
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web) { WriteIndented = true };

    private readonly object _gate = new();
    private readonly string _filePath;
    private readonly ILogger<PersistentAIUsageLog> _logger;
    private readonly int _maxEntries;
    private UsageDocument _document;

    public PersistentAIUsageLog(IApplicationPathService paths, ILogger<PersistentAIUsageLog> logger)
        : this(paths, logger, MaxEntries)
    {
    }

    public PersistentAIUsageLog(IApplicationPathService paths, ILogger<PersistentAIUsageLog> logger, int maxEntries)
    {
        _logger = logger;
        _maxEntries = Math.Clamp(maxEntries, 1, MaxEntries);
        _filePath = Path.Combine(paths.GetApplicationDataDirectory(), "ai-usage-log.json");
        _document = Load();
        TrimToRetention();
    }

    public void Record(AIUsageEntry entry)
    {
        ArgumentNullException.ThrowIfNull(entry);
        var normalized = Normalize(entry);

        lock (_gate)
        {
            _document.Entries.Add(normalized);
            TrimToRetention();
            try
            {
                Persist();
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                _logger.LogError(ex, "AI usage telemetry could not be persisted to {UsageLogPath}. The AI request result is preserved and telemetry remains available in memory for this process.", _filePath);
            }
        }
    }

    public IReadOnlyList<AIUsageEntry> GetRecent(int take = 100, Guid? siteId = null, string? userId = null)
    {
        var limit = Math.Clamp(take, 1, MaxEntries);
        var normalizedUserId = NormalizeUserId(userId);

        lock (_gate)
        {
            return _document.Entries
                .AsEnumerable()
                .Reverse()
                .Where(x => !siteId.HasValue || x.SiteId == siteId)
                .Where(x => normalizedUserId is null || string.Equals(NormalizeUserId(x.UserId), normalizedUserId, StringComparison.OrdinalIgnoreCase))
                .Take(limit)
                .ToArray();
        }
    }

    private UsageDocument Load()
    {
        if (!File.Exists(_filePath)) return new UsageDocument { SchemaVersion = SchemaVersion };

        try
        {
            var document = JsonSerializer.Deserialize<UsageDocument>(File.ReadAllText(_filePath, Encoding.UTF8), JsonOptions);
            if (document is null || document.SchemaVersion <= 0)
                throw new InvalidDataException("AI usage log document is empty or has no schema version.");
            document.Entries ??= new List<AIUsageEntry>();
            return document;
        }
        catch (Exception ex) when (ex is JsonException or IOException or UnauthorizedAccessException or InvalidDataException)
        {
            var quarantinePath = _filePath + $".corrupt-{DateTime.UtcNow:yyyyMMddHHmmssfff}";
            try { File.Move(_filePath, quarantinePath, overwrite: false); }
            catch (Exception moveEx) when (moveEx is IOException or UnauthorizedAccessException)
            {
                _logger.LogWarning(moveEx, "Could not quarantine corrupt AI usage log at {UsageLogPath}.", _filePath);
            }

            _logger.LogError(ex, "AI usage log could not be loaded. A clean log will be created; corrupt data was preserved when possible.");
            return new UsageDocument { SchemaVersion = SchemaVersion };
        }
    }

    private void Persist()
    {
        _document.SchemaVersion = SchemaVersion;
        var directory = Path.GetDirectoryName(_filePath) ?? throw new InvalidOperationException("AI usage log data directory is unavailable.");
        Directory.CreateDirectory(directory);
        var temporaryPath = _filePath + $".tmp-{Guid.NewGuid():N}";

        try
        {
            File.WriteAllText(temporaryPath, JsonSerializer.Serialize(_document, JsonOptions), new UTF8Encoding(false));
            File.Move(temporaryPath, _filePath, overwrite: true);
        }
        finally
        {
            if (File.Exists(temporaryPath))
            {
                try { File.Delete(temporaryPath); }
                catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
                {
                    _logger.LogWarning(ex, "Could not remove temporary AI usage file {UsageLogTempPath}.", temporaryPath);
                }
            }
        }
    }

    private void TrimToRetention()
    {
        if (_document.Entries.Count <= _maxEntries) return;
        _document.Entries.RemoveRange(0, _document.Entries.Count - _maxEntries);
    }

    private static AIUsageEntry Normalize(AIUsageEntry entry)
    {
        var createdAtUtc = entry.CreatedAtUtc.Kind == DateTimeKind.Utc
            ? entry.CreatedAtUtc
            : entry.CreatedAtUtc.ToUniversalTime();

        return entry with
        {
            CreatedAtUtc = createdAtUtc,
            Provider = NormalizeText(entry.Provider, "unknown", 80),
            Model = NormalizeNullable(entry.Model, 160),
            Operation = NormalizeNullable(entry.Operation, 160),
            UserId = NormalizeUserId(entry.UserId),
            InputTokens = Math.Max(0, entry.InputTokens),
            OutputTokens = Math.Max(0, entry.OutputTokens),
            EstimatedCost = Math.Max(0, entry.EstimatedCost),
            Error = NormalizeNullable(entry.Error, 1_000)
        };
    }

    private static string NormalizeText(string? value, string fallback, int maxLength)
    {
        var normalized = string.IsNullOrWhiteSpace(value) ? fallback : value.Trim();
        return normalized.Length <= maxLength ? normalized : normalized[..maxLength];
    }

    private static string? NormalizeNullable(string? value, int maxLength)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var normalized = value.Trim();
        return normalized.Length <= maxLength ? normalized : normalized[..maxLength];
    }

    private static string? NormalizeUserId(string? userId)
    {
        if (string.IsNullOrWhiteSpace(userId)) return null;
        var value = userId.Trim();
        return Guid.TryParse(value, out var guid) ? guid.ToString("D") : NormalizeText(value, "unknown", 128);
    }

    private sealed class UsageDocument
    {
        public int SchemaVersion { get; set; } = PersistentAIUsageLog.SchemaVersion;
        public List<AIUsageEntry> Entries { get; set; } = new();
    }
}
