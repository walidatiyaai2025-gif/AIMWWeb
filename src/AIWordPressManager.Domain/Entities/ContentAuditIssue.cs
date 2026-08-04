using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class ContentAuditIssue : Entity
{
    private ContentAuditIssue() { }

    public ContentAuditIssue(Guid siteId, Guid contentRecordId, string issueCode, string severity, string title, string description, DateTime utcNow)
    {
        SiteId = siteId;
        ContentRecordId = contentRecordId;
        IssueCode = issueCode;
        Severity = severity;
        Title = title;
        Description = description;
        DetectedAtUtc = utcNow;
        MarkUpdated(utcNow);
    }

    public Guid SiteId { get; private set; }
    public Guid ContentRecordId { get; private set; }
    public string IssueCode { get; private set; } = string.Empty;
    public string Severity { get; private set; } = string.Empty;
    public string Title { get; private set; } = string.Empty;
    public string Description { get; private set; } = string.Empty;
    public DateTime DetectedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;
    public WordPressContentRecord ContentRecord { get; private set; } = null!;
}
