using System.Net;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Common.Results;
using AIWordPressManager.Application.SeoAudit;
using AIWordPressManager.Domain.Entities;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Persistence.Audits;

public sealed partial class SeoAuditService(AppDbContext dbContext) : ISeoAuditService
{
    public async Task<Result<SeoAuditSummary>> LoadLatestAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var content = await dbContext.WordPressContentRecords
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .AsNoTracking()
            .ToDictionaryAsync(x => x.Id, cancellationToken);

        var stored = await dbContext.SeoAuditIssues
            .Where(x => x.SiteId == siteId)
            .AsNoTracking()
            .OrderByDescending(x => x.DetectedAtUtc)
            .ToListAsync(cancellationToken);

        var issues = stored.Select(x =>
        {
            content.TryGetValue(x.ContentRecordId, out var item);
            return new SeoAuditIssueDto(
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
        var score = content.Count == 0 ? 0 : Math.Clamp(100 - high * 7 - medium * 3 - low, 0, 100);
        var completedAt = stored.Count == 0 ? DateTimeOffset.MinValue : new DateTimeOffset(stored.Max(x => x.DetectedAtUtc), TimeSpan.Zero);
        return Result.Success(new SeoAuditSummary(score, content.Count, high, medium, low, issues, completedAt));
    }

    public async Task<Result<SeoAuditSummary>> RunAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var content = await dbContext.WordPressContentRecords
            .Where(x => x.SiteId == siteId && x.IsAvailable)
            .OrderByDescending(x => x.ModifiedAtUtc)
            .ToListAsync(cancellationToken);

        if (content.Count == 0)
            return Result.Failure<SeoAuditSummary>(Error.NotFound("Synchronize WordPress content before running the SEO audit."));

        var oldIssues = await dbContext.SeoAuditIssues.Where(x => x.SiteId == siteId).ToListAsync(cancellationToken);
        dbContext.SeoAuditIssues.RemoveRange(oldIssues);

        var issues = new List<SeoAuditIssueDto>();
        var now = DateTime.UtcNow;
        foreach (var item in content)
        {
            var html = item.RenderedContent ?? string.Empty;
            var text = Normalize(html);
            var titleLength = item.Title.Trim().Length;
            var excerptLength = Normalize(item.RenderedExcerpt).Length;
            var hasH1 = H1Regex().IsMatch(html);
            var headingCount = HeadingRegex().Matches(html).Count;
            var internalLinkCount = InternalRelativeLinkRegex().Matches(html).Count;
            var imageCount = ImageRegex().Matches(html).Count;
            var imagesWithoutAlt = ImageWithoutAltRegex().Matches(html).Count;

            AddIf(titleLength > 60, "SEO_TITLE_TOO_LONG", "Medium", $"Title has {titleLength} characters; keep it near 50–60 characters where practical.");
            AddIf(titleLength < 20, "SEO_TITLE_TOO_SHORT", "Medium", $"Title has only {titleLength} characters and may not describe the page clearly.");
            AddIf(string.IsNullOrWhiteSpace(item.Slug), "SEO_MISSING_SLUG", "High", "The synchronized item has no slug.");
            AddIf(item.Slug.Length > 75, "SEO_SLUG_TOO_LONG", "Low", $"Slug has {item.Slug.Length} characters.");
            AddIf(excerptLength == 0, "SEO_MISSING_DESCRIPTION_SOURCE", "Medium", "No excerpt is available as a measurable local source for a meta description.");
            AddIf(excerptLength > 170, "SEO_DESCRIPTION_TOO_LONG", "Low", $"Excerpt has {excerptLength} text characters.");
            AddIf(!hasH1, "SEO_NO_H1_IN_CONTENT", "Low", "No H1 element was found in rendered content. The theme may still render the title as H1.");
            AddIf(text.Length > 700 && headingCount == 0, "SEO_NO_SUBHEADINGS", "Medium", "Long content has no H2–H6 headings in the rendered body.");
            AddIf(text.Length > 600 && internalLinkCount == 0, "SEO_NO_INTERNAL_LINKS", "Medium", "Long content contains no measurable relative internal links.");
            AddIf(imagesWithoutAlt > 0, "SEO_IMAGES_WITHOUT_ALT", "High", $"{imagesWithoutAlt} of {imageCount} image tag(s) appear to have no non-empty alt attribute.");

            void AddIf(bool condition, string code, string severity, string description)
            {
                if (!condition) return;
                dbContext.SeoAuditIssues.Add(new SeoAuditIssue(siteId, item.Id, code, severity, item.Title, description, now));
                issues.Add(new SeoAuditIssueDto(severity, code, item.ContentType, item.WordPressId, item.Title, description, item.Link));
            }
        }

        var high = issues.Count(x => x.Severity == "High");
        var medium = issues.Count(x => x.Severity == "Medium");
        var low = issues.Count(x => x.Severity == "Low");
        var score = Math.Clamp(100 - high * 7 - medium * 3 - low, 0, 100);
        dbContext.SeoAuditSnapshots.Add(new SeoAuditSnapshot(siteId, score, content.Count, high, medium, low, now));
        await dbContext.SaveChangesAsync(cancellationToken);
        return Result.Success(new SeoAuditSummary(score, content.Count, high, medium, low, issues, new DateTimeOffset(now, TimeSpan.Zero)));
    }

    public async Task<Result<IReadOnlyList<SeoAuditHistoryPoint>>> LoadHistoryAsync(Guid siteId, int take = 50, CancellationToken cancellationToken = default)
    {
        var points = await dbContext.SeoAuditSnapshots
            .Where(x => x.SiteId == siteId)
            .AsNoTracking()
            .OrderByDescending(x => x.CapturedAtUtc)
            .Take(Math.Clamp(take, 1, 365))
            .Select(x => new SeoAuditHistoryPoint(
                new DateTimeOffset(x.CapturedAtUtc, TimeSpan.Zero),
                x.Score, x.AuditedItems, x.HighIssues, x.MediumIssues, x.LowIssues))
            .ToListAsync(cancellationToken);
        return Result.Success<IReadOnlyList<SeoAuditHistoryPoint>>(points);
    }

    private static string Normalize(string? html) => WebUtility.HtmlDecode(HtmlTagRegex().Replace(html ?? string.Empty, " ")).Trim();
    [GeneratedRegex("<[^>]+>", RegexOptions.Compiled)] private static partial Regex HtmlTagRegex();
    [GeneratedRegex("<h1\\b", RegexOptions.IgnoreCase | RegexOptions.Compiled)] private static partial Regex H1Regex();
    [GeneratedRegex("<h[2-6]\\b", RegexOptions.IgnoreCase | RegexOptions.Compiled)] private static partial Regex HeadingRegex();
    [GeneratedRegex("<a\\s+[^>]*href\\s*=\\s*[\"'](?:/|\\./|\\../)[^\"']*[\"']", RegexOptions.IgnoreCase | RegexOptions.Compiled)] private static partial Regex InternalRelativeLinkRegex();
    [GeneratedRegex("<img\\b[^>]*>", RegexOptions.IgnoreCase | RegexOptions.Compiled)] private static partial Regex ImageRegex();
    [GeneratedRegex("<img\\b(?:(?!\\balt\\s*=\\s*[\"'][^\"']+[\"']).)*>", RegexOptions.IgnoreCase | RegexOptions.Singleline | RegexOptions.Compiled)] private static partial Regex ImageWithoutAltRegex();
}
