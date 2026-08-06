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
            return Load().Where(x => x.SiteId == siteId).OrderByDescending(x => x.StartedAtUtc).Take(Math.Clamp(take, 1, 500)).ToList();
        }
    }

    public void Record(Guid siteId, string operation, bool succeeded, string message, string? details, DateTime startedAtUtc, DateTime completedAtUtc, int? affectedRecords = null)
    {
        lock (_sync)
        {
            var items = Load();
            items.Add(new SiteOperationHistoryItem(Guid.NewGuid(), siteId, operation, succeeded, message, details, startedAtUtc, completedAtUtc, affectedRecords));
            if (items.Count > 2000) items = items.OrderByDescending(x => x.StartedAtUtc).Take(2000).ToList();
            var temp = _path + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(items, _json));
            File.Move(temp, _path, true);
        }
    }

    public void Clear(Guid siteId)
    {
        lock (_sync)
        {
            var items = Load().Where(x => x.SiteId != siteId).ToList();
            File.WriteAllText(_path, JsonSerializer.Serialize(items, _json));
        }
    }

    private List<SiteOperationHistoryItem> Load()
    {
        if (!File.Exists(_path)) return [];
        try { return JsonSerializer.Deserialize<List<SiteOperationHistoryItem>>(File.ReadAllText(_path), _json) ?? []; }
        catch { return []; }
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
