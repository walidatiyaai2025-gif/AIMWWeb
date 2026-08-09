using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SiteSyncRun : Entity
{
    private SiteSyncRun() { }

    public SiteSyncRun(Guid siteId, DateTime startedAtUtc)
    {
        SiteId = siteId;
        Status = "Running";
        StartedAtUtc = startedAtUtc;
        Message = "Synchronization started.";
        MarkUpdated(startedAtUtc);
    }

    public Guid SiteId { get; private set; }
    public string Status { get; private set; } = string.Empty;
    public DateTime StartedAtUtc { get; private set; }
    public DateTime? CompletedAtUtc { get; private set; }
    public bool WasSkipped { get; private set; }
    public int DownloadedRecords { get; private set; }
    public string Message { get; private set; } = string.Empty;
    public Site Site { get; private set; } = null!;

    public void Complete(string message, int downloadedRecords, bool wasSkipped, DateTime utcNow)
    {
        Status = wasSkipped ? "Skipped" : "Completed";
        WasSkipped = wasSkipped;
        DownloadedRecords = Math.Max(0, downloadedRecords);
        Message = NormalizeMessage(message);
        CompletedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public void Fail(string error, DateTime utcNow)
    {
        Status = "Failed";
        WasSkipped = false;
        DownloadedRecords = 0;
        Message = NormalizeMessage(error);
        CompletedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    private static string NormalizeMessage(string? value)
    {
        var message = string.IsNullOrWhiteSpace(value) ? "No details were provided." : value.Trim();
        return message.Length <= 2000 ? message : message[..2000];
    }
}
