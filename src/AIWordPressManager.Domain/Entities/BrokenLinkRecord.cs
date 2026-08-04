using AIWordPressManager.Domain.Common;

namespace AIWordPressManager.Domain.Entities;

public sealed class BrokenLinkRecord : Entity
{
    private BrokenLinkRecord() { }

    public BrokenLinkRecord(Guid siteId, Guid contentRecordId, string sourceUrl, string targetUrl, int? statusCode, string status, string? errorMessage, DateTime checkedAtUtc)
    {
        SiteId = siteId;
        ContentRecordId = contentRecordId;
        SourceUrl = sourceUrl;
        TargetUrl = targetUrl;
        StatusCode = statusCode;
        Status = status;
        ErrorMessage = errorMessage;
        CheckedAtUtc = checkedAtUtc;
        MarkUpdated(checkedAtUtc);
    }

    public Guid SiteId { get; private set; }
    public Guid ContentRecordId { get; private set; }
    public string SourceUrl { get; private set; } = string.Empty;
    public string TargetUrl { get; private set; } = string.Empty;
    public int? StatusCode { get; private set; }
    public string Status { get; private set; } = string.Empty;
    public string? ErrorMessage { get; private set; }
    public DateTime CheckedAtUtc { get; private set; }
    public Site Site { get; private set; } = null!;
    public WordPressContentRecord ContentRecord { get; private set; } = null!;
}
