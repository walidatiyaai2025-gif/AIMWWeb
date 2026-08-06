using System.Text.Json;

namespace AIWordPressManager.Web.Services;

public sealed class SiteOperationHistoryService
{
    private readonly object _sync = new();
    private readonly string _path;
    private readonly JsonSerializerOptions _json = new(JsonSerializerDefaults.Web) { WriteIndented = true };

    public SiteOperationHistoryService()
    {
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AIWordPressManager", "Data");
        Directory.CreateDirectory(root);
        _path = Path.Combine(root, "site-operation-history.json");
    }

    public IReadOnlyList<SiteOperationHistoryItem> Get(Guid siteId, int take = 100)
    {
        lock (_sync)
        {
            return Load()
                .Where(x => x.SiteId == siteId)
                .OrderByDescending(x => x.StartedAtUtc)
                .Take(Math.Clamp(take, 1, 500))
                .ToList();
        }
    }

    public IReadOnlyList<SiteOperationHistoryItem> GetAll(int take = 250)
    {
        lock (_sync)
        {
            return Load()
                .OrderByDescending(x => x.StartedAtUtc)
                .Take(Math.Clamp(take, 1, 2000))
                .ToList();
        }
    }

    public SiteOperationHistoryItem? GetById(Guid operationId)
    {
        lock (_sync)
        {
            return Load().FirstOrDefault(x => x.Id == operationId);
        }
    }

    public SiteOperationHistorySummary GetSummary(DateTime? sinceUtc = null)
    {
        lock (_sync)
        {
            var query = Load().AsEnumerable();
            if (sinceUtc.HasValue)
            {
                query = query.Where(x => x.StartedAtUtc >= sinceUtc.Value);
            }

            var items = query.ToList();
            var successful = items.Count(x => x.Succeeded);
            var failed = items.Count - successful;
            var averageMilliseconds = items.Count == 0
                ? 0
                : items.Average(x => Math.Max(0, x.Duration.TotalMilliseconds));

            return new SiteOperationHistorySummary(
                items.Count,
                successful,
                failed,
                TimeSpan.FromMilliseconds(averageMilliseconds),
                items.Select(x => x.SiteId).Distinct().Count(),
                items.OrderByDescending(x => x.StartedAtUtc).FirstOrDefault()?.StartedAtUtc);
        }
    }

    public SiteOperationHistoryStorageInfo GetStorageInfo()
    {
        lock (_sync)
        {
            var items = Load();
            var file = new FileInfo(_path);
            return new SiteOperationHistoryStorageInfo(
                _path,
                items.Count,
                file.Exists ? file.Length : 0,
                items.Count == 0 ? null : items.Min(x => x.StartedAtUtc),
                items.Count == 0 ? null : items.Max(x => x.StartedAtUtc),
                items.Select(x => x.SiteId).Distinct().Count());
        }
    }

    public SiteOperationCleanupPreview PreviewCleanup(int olderThanDays, int keepLatest = 100)
    {
        lock (_sync)
        {
            var items = Load().OrderByDescending(x => x.StartedAtUtc).ToList();
            var safeDays = Math.Clamp(olderThanDays, 1, 3650);
            var safeKeep = Math.Clamp(keepLatest, 0, 2000);
            var cutoffUtc = DateTime.UtcNow.AddDays(-safeDays);
            var protectedIds = items.Take(safeKeep).Select(x => x.Id).ToHashSet();
            var removable = items.Where(x => x.StartedAtUtc < cutoffUtc && !protectedIds.Contains(x.Id)).ToList();

            return new SiteOperationCleanupPreview(
                items.Count,
                removable.Count,
                items.Count - removable.Count,
                cutoffUtc,
                safeKeep,
                removable.Count == 0 ? null : removable.Min(x => x.StartedAtUtc),
                removable.Count == 0 ? null : removable.Max(x => x.StartedAtUtc));
        }
    }

    public SiteOperationCleanupResult Cleanup(int olderThanDays, int keepLatest = 100)
    {
        lock (_sync)
        {
            var items = Load().OrderByDescending(x => x.StartedAtUtc).ToList();
            var preview = PreviewCleanup(olderThanDays, keepLatest);
            if (preview.RemovableCount == 0)
            {
                return new SiteOperationCleanupResult(0, items.Count, preview.CutoffUtc, DateTime.UtcNow);
            }

            var protectedIds = items.Take(preview.KeepLatest).Select(x => x.Id).ToHashSet();
            var retained = items
                .Where(x => x.StartedAtUtc >= preview.CutoffUtc || protectedIds.Contains(x.Id))
                .ToList();

            Save(retained);
            return new SiteOperationCleanupResult(items.Count - retained.Count, retained.Count, preview.CutoffUtc, DateTime.UtcNow);
        }
    }

    public void Record(Guid siteId, string operation, bool succeeded, string message, string? details, DateTime startedAtUtc, DateTime completedAtUtc, int? affectedRecords = null)
    {
        lock (_sync)
        {
            var items = Load();
            items.Add(new SiteOperationHistoryItem(Guid.NewGuid(), siteId, operation, succeeded, message, details, startedAtUtc, completedAtUtc, affectedRecords));
            if (items.Count > 2000)
            {
                items = items.OrderByDescending(x => x.StartedAtUtc).Take(2000).ToList();
            }

            Save(items);
        }
    }

    public void Clear(Guid siteId)
    {
        lock (_sync)
        {
            Save(Load().Where(x => x.SiteId != siteId).ToList());
        }
    }

    private List<SiteOperationHistoryItem> Load()
    {
        if (!File.Exists(_path)) return [];
        try
        {
            return JsonSerializer.Deserialize<List<SiteOperationHistoryItem>>(File.ReadAllText(_path), _json) ?? [];
        }
        catch
        {
            return [];
        }
    }

    private void Save(List<SiteOperationHistoryItem> items)
    {
        var temp = _path + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(items, _json));
        File.Move(temp, _path, true);
    }
}

public sealed record SiteOperationHistoryItem(
    Guid Id,
    Guid SiteId,
    string Operation,
    bool Succeeded,
    string Message,
    string? Details,
    DateTime StartedAtUtc,
    DateTime CompletedAtUtc,
    int? AffectedRecords)
{
    public TimeSpan Duration => CompletedAtUtc - StartedAtUtc;
}

public sealed record SiteOperationHistorySummary(
    int Total,
    int Successful,
    int Failed,
    TimeSpan AverageDuration,
    int SiteCount,
    DateTime? LastOperationAtUtc);

public sealed record SiteOperationHistoryStorageInfo(
    string FilePath,
    int RecordCount,
    long FileSizeBytes,
    DateTime? OldestOperationAtUtc,
    DateTime? NewestOperationAtUtc,
    int SiteCount);

public sealed record SiteOperationCleanupPreview(
    int TotalCount,
    int RemovableCount,
    int RetainedCount,
    DateTime CutoffUtc,
    int KeepLatest,
    DateTime? OldestRemovableAtUtc,
    DateTime? NewestRemovableAtUtc);

public sealed record SiteOperationCleanupResult(
    int RemovedCount,
    int RemainingCount,
    DateTime CutoffUtc,
    DateTime CompletedAtUtc);