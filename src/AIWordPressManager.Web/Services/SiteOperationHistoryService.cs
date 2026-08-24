using System.Text;
using System.Text.Json;

namespace AIWordPressManager.Web.Services;

public sealed class SiteOperationHistoryService
{
    private static readonly TimeSpan MutationLockTimeout = TimeSpan.FromSeconds(5);
    private static readonly TimeSpan MutationLockRetryDelay = TimeSpan.FromMilliseconds(25);
    private readonly object _sync = new();
    private readonly string _path;
    private readonly JsonSerializerOptions _json = new(JsonSerializerDefaults.Web) { WriteIndented = true };

    public SiteOperationHistoryService()
        : this(Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "AIWordPressManager",
            "Data",
            "site-operation-history.json"))
    {
    }

    public SiteOperationHistoryService(string path)
    {
        _path = Path.GetFullPath(path);
        var root = Path.GetDirectoryName(_path);
        if (!string.IsNullOrWhiteSpace(root)) Directory.CreateDirectory(root);
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

    public IReadOnlyList<SiteOperationHistoryItem> Get(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        Guid siteId,
        int take = 100)
    {
        RequireOwner(ownerUserId);
        var owned = NormalizeOwnedSites(ownedSiteIds);
        if (!owned.Contains(siteId)) return [];

        lock (_sync)
        {
            return Load()
                .Where(x => x.SiteId == siteId && IsVisibleTo(x, ownerUserId, owned))
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

    public IReadOnlyList<SiteOperationHistoryItem> GetAll(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        int take = 250)
    {
        RequireOwner(ownerUserId);
        var owned = NormalizeOwnedSites(ownedSiteIds);
        lock (_sync)
        {
            return Load()
                .Where(x => IsVisibleTo(x, ownerUserId, owned))
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

    public SiteOperationHistoryItem? GetById(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        Guid operationId)
    {
        RequireOwner(ownerUserId);
        var owned = NormalizeOwnedSites(ownedSiteIds);
        lock (_sync)
        {
            return Load().FirstOrDefault(x => x.Id == operationId && IsVisibleTo(x, ownerUserId, owned));
        }
    }

    public SiteOperationHistorySummary GetSummary(DateTime? sinceUtc = null) =>
        BuildSummary(GetAll(2000), sinceUtc);

    public SiteOperationHistorySummary GetSummary(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        DateTime? sinceUtc = null) =>
        BuildSummary(GetAll(ownerUserId, ownedSiteIds, 2000), sinceUtc);

    public SiteOperationHistoryStorageInfo GetStorageInfo()
    {
        lock (_sync)
        {
            var items = Load();
            var file = new FileInfo(_path);
            return BuildStorageInfo(items, file.Exists ? file.Length : 0);
        }
    }

    public SiteOperationHistoryStorageInfo GetStorageInfo(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds)
    {
        RequireOwner(ownerUserId);
        var owned = NormalizeOwnedSites(ownedSiteIds);
        lock (_sync)
        {
            var items = Load().Where(x => IsVisibleTo(x, ownerUserId, owned)).ToList();
            var visibleBytes = items.Count == 0
                ? 0
                : Encoding.UTF8.GetByteCount(JsonSerializer.Serialize(items, _json));
            return BuildStorageInfo(items, visibleBytes);
        }
    }

    public SiteOperationCleanupPreview PreviewCleanup(int olderThanDays, int keepLatest = 100)
    {
        lock (_sync)
        {
            return BuildCleanupPreview(Load(), olderThanDays, keepLatest);
        }
    }

    public SiteOperationCleanupPreview PreviewCleanup(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        int olderThanDays,
        int keepLatest = 100)
    {
        RequireOwner(ownerUserId);
        var owned = NormalizeOwnedSites(ownedSiteIds);
        lock (_sync)
        {
            var visible = Load().Where(x => IsVisibleTo(x, ownerUserId, owned)).ToList();
            return BuildCleanupPreview(visible, olderThanDays, keepLatest);
        }
    }

    public SiteOperationCleanupResult Cleanup(int olderThanDays, int keepLatest = 100)
    {
        lock (_sync)
        {
            using var mutationLease = AcquireMutationLease();
            var items = Load().OrderByDescending(x => x.StartedAtUtc).ToList();
            var preview = BuildCleanupPreview(items, olderThanDays, keepLatest);
            if (preview.RemovableCount == 0)
                return new SiteOperationCleanupResult(0, items.Count, preview.CutoffUtc, DateTime.UtcNow);

            var protectedIds = items.Take(preview.KeepLatest).Select(x => x.Id).ToHashSet();
            var retained = items
                .Where(x => x.StartedAtUtc >= preview.CutoffUtc || protectedIds.Contains(x.Id))
                .ToList();
            Save(retained);
            return new SiteOperationCleanupResult(items.Count - retained.Count, retained.Count, preview.CutoffUtc, DateTime.UtcNow);
        }
    }

    public SiteOperationCleanupResult Cleanup(
        Guid ownerUserId,
        IReadOnlyCollection<Guid> ownedSiteIds,
        int olderThanDays,
        int keepLatest = 100)
    {
        RequireOwner(ownerUserId);
        var owned = NormalizeOwnedSites(ownedSiteIds);
        lock (_sync)
        {
            using var mutationLease = AcquireMutationLease();
            var all = Load().OrderByDescending(x => x.StartedAtUtc).ToList();
            var visible = all.Where(x => IsVisibleTo(x, ownerUserId, owned)).ToList();
            var preview = BuildCleanupPreview(visible, olderThanDays, keepLatest);
            if (preview.RemovableCount == 0)
                return new SiteOperationCleanupResult(0, visible.Count, preview.CutoffUtc, DateTime.UtcNow);

            var protectedIds = visible.Take(preview.KeepLatest).Select(x => x.Id).ToHashSet();
            var removableIds = visible
                .Where(x => x.StartedAtUtc < preview.CutoffUtc && !protectedIds.Contains(x.Id))
                .Select(x => x.Id)
                .ToHashSet();
            var retained = all.Where(x => !removableIds.Contains(x.Id)).ToList();
            Save(retained);
            return new SiteOperationCleanupResult(
                removableIds.Count,
                visible.Count - removableIds.Count,
                preview.CutoffUtc,
                DateTime.UtcNow);
        }
    }

    public void Record(
        Guid siteId,
        string operation,
        bool succeeded,
        string message,
        string? details,
        DateTime startedAtUtc,
        DateTime completedAtUtc,
        int? affectedRecords = null) =>
        RecordCore(null, siteId, operation, succeeded, message, details, startedAtUtc, completedAtUtc, affectedRecords, null);

    public void Record(
        Guid ownerUserId,
        Guid siteId,
        string operation,
        bool succeeded,
        string message,
        string? details,
        DateTime startedAtUtc,
        DateTime completedAtUtc,
        int? affectedRecords = null,
        Guid? executionJobId = null)
    {
        RequireOwner(ownerUserId);
        if (siteId == Guid.Empty) throw new ArgumentException("Site ID is required.", nameof(siteId));
        RecordCore(ownerUserId, siteId, operation, succeeded, message, details, startedAtUtc, completedAtUtc, affectedRecords, executionJobId);
    }

    public void Clear(Guid siteId)
    {
        lock (_sync)
        {
            using var mutationLease = AcquireMutationLease();
            Save(Load().Where(x => x.SiteId != siteId).ToList());
        }
    }

    public void Clear(Guid ownerUserId, Guid siteId)
    {
        RequireOwner(ownerUserId);
        lock (_sync)
        {
            using var mutationLease = AcquireMutationLease();
            Save(Load().Where(x =>
                x.SiteId != siteId ||
                (x.OwnerUserId.HasValue && x.OwnerUserId.Value != ownerUserId)).ToList());
        }
    }

    private void RecordCore(
        Guid? ownerUserId,
        Guid siteId,
        string operation,
        bool succeeded,
        string message,
        string? details,
        DateTime startedAtUtc,
        DateTime completedAtUtc,
        int? affectedRecords,
        Guid? executionJobId)
    {
        lock (_sync)
        {
            using var mutationLease = AcquireMutationLease();
            var items = Load();
            items.Add(new SiteOperationHistoryItem(
                Guid.NewGuid(),
                siteId,
                operation,
                succeeded,
                message,
                details,
                startedAtUtc,
                completedAtUtc,
                affectedRecords,
                ownerUserId,
                executionJobId));
            if (items.Count > 2000)
                items = items.OrderByDescending(x => x.StartedAtUtc).Take(2000).ToList();
            Save(items);
        }
    }

    private static SiteOperationHistorySummary BuildSummary(
        IEnumerable<SiteOperationHistoryItem> source,
        DateTime? sinceUtc)
    {
        var query = source;
        if (sinceUtc.HasValue) query = query.Where(x => x.StartedAtUtc >= sinceUtc.Value);
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

    private SiteOperationHistoryStorageInfo BuildStorageInfo(
        IReadOnlyCollection<SiteOperationHistoryItem> items,
        long bytes) =>
        new(
            _path,
            items.Count,
            bytes,
            items.Count == 0 ? null : items.Min(x => x.StartedAtUtc),
            items.Count == 0 ? null : items.Max(x => x.StartedAtUtc),
            items.Select(x => x.SiteId).Distinct().Count());

    private static SiteOperationCleanupPreview BuildCleanupPreview(
        IEnumerable<SiteOperationHistoryItem> source,
        int olderThanDays,
        int keepLatest)
    {
        var items = source.OrderByDescending(x => x.StartedAtUtc).ToList();
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

    private static HashSet<Guid> NormalizeOwnedSites(IReadOnlyCollection<Guid> ownedSiteIds) =>
        ownedSiteIds.Where(x => x != Guid.Empty).ToHashSet();

    private static bool IsVisibleTo(
        SiteOperationHistoryItem item,
        Guid ownerUserId,
        IReadOnlySet<Guid> ownedSiteIds) =>
        item.OwnerUserId.HasValue
            ? item.OwnerUserId.Value == ownerUserId
            : ownedSiteIds.Contains(item.SiteId);

    private static void RequireOwner(Guid ownerUserId)
    {
        if (ownerUserId == Guid.Empty) throw new ArgumentException("Owner user ID is required.", nameof(ownerUserId));
    }

    private List<SiteOperationHistoryItem> Load()
    {
        if (!File.Exists(_path)) return [];
        try
        {
            return JsonSerializer.Deserialize<List<SiteOperationHistoryItem>>(File.ReadAllText(_path), _json)
                ?? throw new JsonException("The site operation history document cannot be null.");
        }
        catch (Exception ex) when (ex is JsonException or IOException or UnauthorizedAccessException or NotSupportedException)
        {
            throw new InvalidDataException(
                "Site operation history storage is unreadable. No records were treated as empty.",
                ex);
        }
    }

    private FileStream AcquireMutationLease()
    {
        var lockPath = _path + ".lock";
        var deadline = DateTime.UtcNow + MutationLockTimeout;
        while (true)
        {
            try
            {
                return new FileStream(
                    lockPath,
                    FileMode.OpenOrCreate,
                    FileAccess.ReadWrite,
                    FileShare.None,
                    bufferSize: 1,
                    FileOptions.None);
            }
            catch (IOException) when (DateTime.UtcNow < deadline)
            {
                Thread.Sleep(MutationLockRetryDelay);
            }
            catch (UnauthorizedAccessException ex)
            {
                throw new InvalidOperationException(
                    "Site operation history storage cannot acquire its mutation lock.",
                    ex);
            }
            catch (IOException ex)
            {
                throw new IOException(
                    "Site operation history storage is busy. Retry the operation.",
                    ex);
            }
        }
    }

    private void Save(List<SiteOperationHistoryItem> items)
    {
        var temp = _path + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(items, _json));
        for (var attempt = 0; ; attempt++)
        {
            try
            {
                File.Move(temp, _path, true);
                return;
            }
            catch (IOException) when (attempt < 4)
            {
                Thread.Sleep(MutationLockRetryDelay);
            }
            catch (IOException ex)
            {
                throw new IOException(
                    "Site operation history could not commit its durable update after bounded retries.",
                    ex);
            }
        }
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
    int? AffectedRecords,
    Guid? OwnerUserId = null,
    Guid? ExecutionJobId = null)
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
