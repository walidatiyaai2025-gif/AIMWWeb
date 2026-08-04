using System.Net;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Common.Results;
using AIWordPressManager.Application.ContentAudit;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Audits;

public sealed partial class ContentAuditService(AppDbContext dbContext) : IContentAuditService
{
    public async Task<Result<ContentAuditSummary>> LoadLatestAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var content = await dbContext.WordPressContentRecords
            .Where(x => x.SiteId == siteId)
            .AsNoTracking()
            .ToDictionaryAsync(x => x.Id, cancellationToken);

        var stored = await dbContext.ContentAuditIssues
            .Where(x => x.SiteId == siteId)
            .AsNoTracking()
            .OrderByDescending(x => x.DetectedAtUtc)
            .ToListAsync(cancellationToken);

        var issues = stored.Select(x =>
        {
            content.TryGetValue(x.ContentRecordId, out var item);
            return new ContentAuditIssueDto(
                x.Severity,
                x.IssueCode,
                item?.ContentType ?? "Unknown",
                item?.WordPressId ?? 0,
                x.Title,
                x.Description,
                item?.Link ?? string.Empty);
        }).ToList();

        var high = issues.Count(x => x.Severity == "High");
        var medium = issues.Count(x => x.Severity == "Medium");
        var low = issues.Count(x => x.Severity == "Low");
        var score = content.Count == 0 ? 0 : Math.Clamp(100 - high * 8 - medium * 4 - low, 0, 100);
        var completedAt = stored.Count == 0 ? DateTimeOffset.MinValue : new DateTimeOffset(stored.Max(x => x.DetectedAtUtc), TimeSpan.Zero);
        return Result.Success(new ContentAuditSummary(score, content.Count, high, medium, low, issues, completedAt));
    }

    public async Task<Result<ContentAuditSummary>> RunAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var content = await dbContext.WordPressContentRecords.Where(x => x.SiteId == siteId).OrderByDescending(x => x.ModifiedAtUtc).ToListAsync(cancellationToken);
        if (content.Count == 0) return Result.Failure<ContentAuditSummary>(Error.NotFound("Synchronize WordPress content before running the audit."));

        var oldIssues = await dbContext.ContentAuditIssues.Where(x => x.SiteId == siteId).ToListAsync(cancellationToken);
        dbContext.ContentAuditIssues.RemoveRange(oldIssues);
        var issues = new List<ContentAuditIssueDto>();
        var now = DateTime.UtcNow;
        foreach (var item in content)
        {
            var text = Normalize(item.RenderedContent);
            AddIf(item.Title.Length > 65, "TITLE_TOO_LONG", "Medium", "Title is longer than 65 characters.");
            AddIf(item.Title.Length < 10, "TITLE_TOO_SHORT", "Medium", "Title is shorter than 10 characters.");
            AddIf(string.IsNullOrWhiteSpace(item.Slug), "MISSING_SLUG", "High", "The content has no slug.");
            AddIf(text.Length < 300, "THIN_CONTENT", "High", $"Rendered content contains only {text.Length} text characters.");
            AddIf(string.IsNullOrWhiteSpace(item.RenderedExcerpt), "MISSING_EXCERPT", "Low", "No excerpt is available.");

            void AddIf(bool condition, string code, string severity, string description)
            {
                if (!condition) return;
                dbContext.ContentAuditIssues.Add(new ContentAuditIssue(siteId, item.Id, code, severity, item.Title, description, now));
                issues.Add(new ContentAuditIssueDto(severity, code, item.ContentType, item.WordPressId, item.Title, description, item.Link));
            }
        }
        await dbContext.SaveChangesAsync(cancellationToken);
        var high = issues.Count(x => x.Severity == "High");
        var medium = issues.Count(x => x.Severity == "Medium");
        var low = issues.Count(x => x.Severity == "Low");
        var score = Math.Clamp(100 - high * 8 - medium * 4 - low, 0, 100);
        return Result.Success(new ContentAuditSummary(score, content.Count, high, medium, low, issues, DateTimeOffset.Now));
    }

    private static string Normalize(string html) => WebUtility.HtmlDecode(HtmlTagRegex().Replace(html ?? string.Empty, " ")).Trim();
    [GeneratedRegex("<[^>]+>", RegexOptions.Compiled)] private static partial Regex HtmlTagRegex();
}
