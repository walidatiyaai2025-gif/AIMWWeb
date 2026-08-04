using AIWordPressManager.Application.Common.Results;

namespace AIWordPressManager.Application.ContentAudit;

public interface IContentAuditService
{
    Task<Result<ContentAuditSummary>> LoadLatestAsync(Guid siteId, CancellationToken cancellationToken = default);
    Task<Result<ContentAuditSummary>> RunAsync(Guid siteId, CancellationToken cancellationToken = default);
}

public sealed record ContentAuditSummary(int Score, int AuditedItems, int HighIssues, int MediumIssues, int LowIssues, IReadOnlyList<ContentAuditIssueDto> Issues, DateTimeOffset CompletedAt);
public sealed record ContentAuditIssueDto(string Severity, string Code, string ContentType, int WordPressId, string ContentTitle, string Description, string Link);
