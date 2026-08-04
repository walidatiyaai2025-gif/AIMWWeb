using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class SeoAuditSnapshot : Entity
{
    private SeoAuditSnapshot() { }

    public SeoAuditSnapshot(Guid siteId, int score, int auditedItems, int highIssues, int mediumIssues, int lowIssues, DateTime capturedAtUtc)
    {
        SiteId = siteId;
        Score = score;
        AuditedItems = auditedItems;
        HighIssues = highIssues;
        MediumIssues = mediumIssues;
        LowIssues = lowIssues;
        CapturedAtUtc = capturedAtUtc;
        MarkUpdated(capturedAtUtc);
    }

    public Guid SiteId { get; private set; }
    public int Score { get; private set; }
    public int AuditedItems { get; private set; }
    public int HighIssues { get; private set; }
    public int MediumIssues { get; private set; }
    public int LowIssues { get; private set; }
    public DateTime CapturedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;
}
