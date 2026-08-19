using System.Net;
using System.Text.RegularExpressions;
using AIWordPressManager.Application.Abstractions.Billing;
using AIWordPressManager.Domain.Entities;
using AIWordPressManager.Persistence;
using AIWordPressManager.Persistence.Billing;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class SeoAnalysisWebService(
    AppDbContext dbContext,
    CurrentUserContext currentUser,
    IAccountEntitlementEnforcementService entitlementEnforcement)
{
    public SeoAnalysisWebService(AppDbContext dbContext, CurrentUserContext currentUser)
        : this(
            dbContext,
            currentUser,
            new AccountEntitlementEnforcementService(dbContext, new PlanEntitlementService(dbContext)))
    {
    }

    public async Task<SeoAnalysisView?> AnalyzeAsync(Guid siteId, string? query = null, string type = "all", CancellationToken cancellationToken = default)
    {
        var ownerUserId = currentUser.RequireUserId();
        var site = await dbContext.Sites
            .AsNoTracking()
            .FirstOrDefaultAsync(x => x.Id == siteId && x.OwnerUserId == ownerUserId, cancellationToken);
        if (site is null) return null;

        await entitlementEnforcement.RequireBooleanCapabilityAsync(
            ownerUserId,
            EntitlementDefinitionCatalog.PremiumSeo,
            cancellationToken);

        var contentQuery = dbContext.WordPressContentRecords.AsNoTracking()
            .Where(x => x.SiteId == siteId && x.IsAvailable);

        if (type is "post" or "page") contentQuery = contentQuery.Where(x => x.ContentType == type);
        if (!string.IsNullOrWhiteSpace(query))
        {
            var trimmed = query.Trim();
            contentQuery = contentQuery.Where(x => x.Title.Contains(trimmed) || x.Slug.Contains(trimmed));
        }

        var records = await contentQuery.OrderByDescending(x => x.ModifiedAtUtc).Take(1000).ToListAsync(cancellationToken);
        var items = records.Select(item => SeoRuleEngine.Analyze(new SeoRuleInput(
                item.WordPressId,
                item.ContentType,
                item.Title,
                item.Slug,
                item.Link,
                item.Status,
                item.RenderedExcerpt,
                item.RenderedContent)))
            .OrderBy(x => x.Score)
            .ThenByDescending(x => x.Issues.Count)
            .ToList();

        var average = items.Count == 0 ? 0 : (int)Math.Round(items.Average(x => x.Score));
        return new SeoAnalysisView(site.Name, items, new SeoSummary(
            items.Count,
            average,
            items.Count(x => x.Score >= 80),
            items.Count(x => x.Score is >= 50 and < 80),
            items.Count(x => x.Score < 50),
            items.Sum(x => x.Issues.Count)));
    }
}

public static class SeoRuleEngine
{
    private static readonly Regex WordRegex = new(@"\p{L}[\p{L}\p{M}\p{N}'’-]*", RegexOptions.Compiled);
    private static readonly Regex HeadingRegex = new(@"<h[1-6]\b", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex InternalLinkRegex = new(@"<a\b[^>]*href\s*=\s*[""'](?!https?://|mailto:|tel:|#)[^""']+[""']", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex ImageRegex = new(@"<img\b[^>]*>", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex AltRegex = new(@"\balt\s*=\s*[""'][^""']+[""']", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex ScriptStyleRegex = new(@"<(script|style)\b[^>]*>.*?</\1>", RegexOptions.IgnoreCase | RegexOptions.Singleline | RegexOptions.Compiled);
    private static readonly Regex HtmlTagRegex = new("<.*?>", RegexOptions.Singleline | RegexOptions.Compiled);

    public static SeoAnalysisItem Analyze(SeoRuleInput item)
    {
        ArgumentNullException.ThrowIfNull(item);

        var title = PlainText(item.Title).Trim();
        var excerpt = PlainText(item.RenderedExcerpt).Trim();
        var html = item.RenderedContent ?? string.Empty;
        var text = PlainText(html);
        var wordCount = WordRegex.Matches(text).Count;
        var headingCount = HeadingRegex.Matches(html).Count;
        var internalLinks = InternalLinkRegex.Matches(html).Count;
        var imageMatches = ImageRegex.Matches(html);
        var imagesWithoutAlt = imageMatches.Cast<Match>().Count(m => !AltRegex.IsMatch(m.Value));
        var issues = new List<SeoIssue>();
        var score = 100;

        Add(title.Length == 0, 25, "MissingTitle", "High");
        Add(title.Length is > 0 and < 30, 8, "ShortTitle", "Medium");
        Add(title.Length > 60, 10, "LongTitle", "Medium");
        Add(string.IsNullOrWhiteSpace(excerpt), 15, "MissingDescription", "High");
        Add(excerpt.Length > 160, 6, "LongDescription", "Low");
        Add(wordCount < 300, 15, "ThinContent", "High");
        Add(headingCount == 0 && wordCount > 150, 8, "MissingHeadings", "Medium");
        Add(internalLinks == 0 && wordCount > 200, 8, "MissingInternalLinks", "Medium");
        Add(imagesWithoutAlt > 0, Math.Min(15, imagesWithoutAlt * 5), "ImagesMissingAlt", "High", imagesWithoutAlt);
        Add(string.IsNullOrWhiteSpace(item.Slug), 10, "MissingSlug", "Medium");

        return new SeoAnalysisItem(
            item.WordPressId,
            item.ContentType,
            title,
            item.Slug ?? string.Empty,
            item.Link ?? string.Empty,
            item.Status ?? string.Empty,
            Math.Max(0, score),
            wordCount,
            headingCount,
            internalLinks,
            imageMatches.Count,
            imagesWithoutAlt,
            issues);

        void Add(bool condition, int penalty, string code, string severity, int count = 1)
        {
            if (!condition) return;
            score -= penalty;
            issues.Add(new SeoIssue(code, severity, count));
        }
    }

    public static string PlainText(string? value)
    {
        var withoutScripts = ScriptStyleRegex.Replace(value ?? string.Empty, " ");
        return WebUtility.HtmlDecode(HtmlTagRegex.Replace(withoutScripts, " "));
    }
}

public sealed record SeoRuleInput(
    int WordPressId,
    string ContentType,
    string? Title,
    string? Slug,
    string? Link,
    string? Status,
    string? RenderedExcerpt,
    string? RenderedContent);

public sealed record SeoAnalysisView(string SiteName, IReadOnlyList<SeoAnalysisItem> Items, SeoSummary Summary);
public sealed record SeoSummary(int Total, int AverageScore, int Good, int NeedsImprovement, int Poor, int TotalIssues);
public sealed record SeoAnalysisItem(int WordPressId, string ContentType, string Title, string Slug, string Link, string Status, int Score, int WordCount, int HeadingCount, int InternalLinks, int ImageCount, int ImagesWithoutAlt, IReadOnlyList<SeoIssue> Issues);
public sealed record SeoIssue(string Code, string Severity, int Count);
