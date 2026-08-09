using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.SeoAudit;

public interface ISeoAuditService
{
    Task<Result<SeoAuditSummary>> LoadLatestAsync(Guid siteId, Guid ownerUserId, CancellationToken cancellationToken = default);
    Task<Result<SeoAuditSummary>> RunAsync(Guid siteId, Guid ownerUserId, CancellationToken cancellationToken = default);
    Task<Result<SeoAuditSummary>> SaveAsync(Guid siteId, Guid ownerUserId, SeoAuditCapture capture, CancellationToken cancellationToken = default);
    Task<Result<IReadOnlyList<SeoAuditHistoryPoint>>> LoadHistoryAsync(Guid siteId, Guid ownerUserId, int take = 50, CancellationToken cancellationToken = default);
}

public sealed record SeoAuditCapture(
    int Score,
    int AuditedItems,
    IReadOnlyList<SeoAuditCaptureIssue> Issues,
    DateTimeOffset CapturedAt);

public sealed record SeoAuditCaptureIssue(
    string Severity,
    string Code,
    string ContentType,
    int WordPressId,
    string ContentTitle,
    string Description,
    string Link);

public sealed record SeoAuditIssueDto(
    string Severity,
    string Code,
    string ContentType,
    int WordPressId,
    string ContentTitle,
    string Description,
    string Link);

public sealed record SeoAuditSummary(
    int Score,
    int AuditedItems,
    int HighIssues,
    int MediumIssues,
    int LowIssues,
    IReadOnlyList<SeoAuditIssueDto> Issues,
    DateTimeOffset CompletedAt);

public sealed record SeoAuditHistoryPoint(DateTimeOffset CapturedAt, int Score, int AuditedItems, int HighIssues, int MediumIssues, int LowIssues);
