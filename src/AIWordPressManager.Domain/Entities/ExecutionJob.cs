using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ExecutionJob : Entity
{
    private ExecutionJob() { }

    public ExecutionJob(Guid siteId, string jobType, DateTime utcNow)
    {
        SiteId = siteId;
        JobType = jobType;
        Status = "Running";
        StartedAtUtc = utcNow;
        CurrentStep = "Starting";
        MarkUpdated(utcNow);
    }

    public Guid SiteId { get; private set; }
    public string JobType { get; private set; } = string.Empty;
    public string Status { get; private set; } = string.Empty;
    public int ProgressPercent { get; private set; }
    public string CurrentStep { get; private set; } = string.Empty;
    public string? ErrorDetails { get; private set; }
    public DateTime StartedAtUtc { get; private set; }
    public DateTime? CompletedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;

    public void ReportProgress(int percent, string step, DateTime utcNow)
    {
        ProgressPercent = Math.Clamp(percent, 0, 100);
        CurrentStep = step;
        MarkUpdated(utcNow);
    }

    public void Complete(DateTime utcNow)
    {
        Status = "Completed";
        ProgressPercent = 100;
        CurrentStep = "Completed";
        CompletedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void Fail(string error, DateTime utcNow)
    {
        Status = "Failed";
        ErrorDetails = error;
        CurrentStep = "Failed";
        CompletedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void Cancel(DateTime utcNow)
    {
        Status = "Cancelled";
        CurrentStep = "Cancelled";
        CompletedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }
}
