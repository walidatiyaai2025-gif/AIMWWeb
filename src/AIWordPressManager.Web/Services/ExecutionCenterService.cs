using System.Collections.Concurrent;

namespace AIWordPressManager.Web.Services;

public sealed class ExecutionCenterService : IDisposable
{
    private readonly ConcurrentDictionary<Guid, ExecutionJob> _jobs = new();
    private readonly ConcurrentQueue<ExecutionActivity> _activities = new();
    private readonly Timer _timer;

    public ExecutionCenterService()
    {
        Seed();
        _timer = new Timer(_ => Tick(), null, TimeSpan.FromSeconds(2), TimeSpan.FromSeconds(2));
    }

    public IReadOnlyList<ExecutionJob> GetJobs() => _jobs.Values.OrderByDescending(x => x.CreatedAtUtc).ToList();
    public IReadOnlyList<ExecutionActivity> GetActivities(int take = 30) => _activities.Reverse().Take(take).ToList();

    public ExecutionJob Enqueue(string title, string type, string siteName, int totalItems)
    {
        var job = new ExecutionJob(Guid.NewGuid(), title, type, siteName, "Waiting", 0, Math.Max(1, totalItems), 0, DateTime.UtcNow, null, null, null);
        _jobs[job.Id] = job;
        AddActivity(job.Id, "Info", $"Queued: {title}");
        return job;
    }

    public void Cancel(Guid id)
    {
        if (!_jobs.TryGetValue(id, out var job) || job.Status is "Completed" or "Cancelled") return;
        _jobs[id] = job with { Status = "Cancelled", CompletedAtUtc = DateTime.UtcNow };
        AddActivity(id, "Warning", "Job cancelled by user.");
    }

    public void Retry(Guid id)
    {
        if (!_jobs.TryGetValue(id, out var job) || job.Status is not ("Failed" or "Cancelled")) return;
        _jobs[id] = job with { Status = "Waiting", ProcessedItems = 0, Progress = 0, StartedAtUtc = null, CompletedAtUtc = null, Error = null };
        AddActivity(id, "Info", "Job queued for retry.");
    }

    public void Pause(Guid id)
    {
        if (!_jobs.TryGetValue(id, out var job) || job.Status != "Running") return;
        _jobs[id] = job with { Status = "Paused" };
        AddActivity(id, "Warning", "Job paused.");
    }

    public void Resume(Guid id)
    {
        if (!_jobs.TryGetValue(id, out var job) || job.Status != "Paused") return;
        _jobs[id] = job with { Status = "Running" };
        AddActivity(id, "Info", "Job resumed.");
    }

    private void Tick()
    {
        var running = _jobs.Values.FirstOrDefault(x => x.Status == "Running");
        if (running is null)
        {
            var waiting = _jobs.Values.OrderBy(x => x.CreatedAtUtc).FirstOrDefault(x => x.Status == "Waiting");
            if (waiting is not null)
            {
                _jobs[waiting.Id] = waiting with { Status = "Running", StartedAtUtc = DateTime.UtcNow };
                AddActivity(waiting.Id, "Info", "Job started.");
            }
            return;
        }

        var step = Math.Max(1, running.TotalItems / 20);
        var processed = Math.Min(running.TotalItems, running.ProcessedItems + step);
        var progress = (int)Math.Round(processed * 100d / running.TotalItems);
        if (processed >= running.TotalItems)
        {
            _jobs[running.Id] = running with { Status = "Completed", ProcessedItems = processed, Progress = 100, CompletedAtUtc = DateTime.UtcNow };
            AddActivity(running.Id, "Success", "Job completed successfully.");
        }
        else
        {
            _jobs[running.Id] = running with { ProcessedItems = processed, Progress = progress };
        }
    }

    private void Seed()
    {
        Enqueue("Publish selected posts", "Bulk Publish", "WALKA Store", 48);
        Enqueue("Synchronize WordPress content", "Synchronization", "Corporate Site", 220);
        var failed = new ExecutionJob(Guid.NewGuid(), "SEO audit", "SEO", "Travel Blog", "Failed", 36, 100, 36, DateTime.UtcNow.AddMinutes(-18), DateTime.UtcNow.AddMinutes(-17), DateTime.UtcNow.AddMinutes(-14), "WordPress API timeout");
        _jobs[failed.Id] = failed;
        AddActivity(failed.Id, "Error", failed.Error!);
    }

    private void AddActivity(Guid jobId, string level, string message) =>
        _activities.Enqueue(new ExecutionActivity(Guid.NewGuid(), jobId, DateTime.UtcNow, level, message));

    public void Dispose() => _timer.Dispose();
}

public sealed record ExecutionJob(Guid Id, string Title, string Type, string SiteName, string Status, int Progress, int TotalItems, int ProcessedItems, DateTime CreatedAtUtc, DateTime? StartedAtUtc, DateTime? CompletedAtUtc, string? Error);
public sealed record ExecutionActivity(Guid Id, Guid JobId, DateTime CreatedAtUtc, string Level, string Message);
