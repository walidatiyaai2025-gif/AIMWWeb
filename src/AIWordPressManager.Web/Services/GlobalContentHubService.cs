using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public static class GlobalContentHubService
{
    public static async Task<GlobalContentHubView> GetAsync(
        AppDbContext dbContext,
        CancellationToken cancellationToken = default)
    {
        var sites = await dbContext.Sites
            .AsNoTracking()
            .OrderBy(x => x.Name)
            .Select(x => new { x.Id, x.Name })
            .ToListAsync(cancellationToken);

        var content = await dbContext.WordPressContentRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .GroupBy(x => x.SiteId)
            .Select(group => new
            {
                SiteId = group.Key,
                Posts = group.Count(x => x.ContentType == "post"),
                Pages = group.Count(x => x.ContentType == "page"),
                Published = group.Count(x => x.Status == "publish"),
                Drafts = group.Count(x => x.Status == "draft"),
                LastSync = group.Max(x => (DateTime?)x.LastSynchronizedAtUtc)
            })
            .ToDictionaryAsync(x => x.SiteId, cancellationToken);

        var media = await dbContext.WordPressMediaRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .GroupBy(x => x.SiteId)
            .Select(group => new
            {
                SiteId = group.Key,
                Count = group.Count(),
                LastSync = group.Max(x => (DateTime?)x.LastSynchronizedAtUtc)
            })
            .ToDictionaryAsync(x => x.SiteId, cancellationToken);

        var categories = await dbContext.WordPressCategoryRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .GroupBy(x => x.SiteId)
            .Select(group => new
            {
                SiteId = group.Key,
                Count = group.Count(),
                LastSync = group.Max(x => (DateTime?)x.LastSynchronizedAtUtc)
            })
            .ToDictionaryAsync(x => x.SiteId, cancellationToken);

        var tags = await dbContext.WordPressTagRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .GroupBy(x => x.SiteId)
            .Select(group => new
            {
                SiteId = group.Key,
                Count = group.Count(),
                LastSync = group.Max(x => (DateTime?)x.LastSynchronizedAtUtc)
            })
            .ToDictionaryAsync(x => x.SiteId, cancellationToken);

        var rows = sites.Select(site =>
        {
            content.TryGetValue(site.Id, out var siteContent);
            media.TryGetValue(site.Id, out var siteMedia);
            categories.TryGetValue(site.Id, out var siteCategories);
            tags.TryGetValue(site.Id, out var siteTags);

            var lastSync = new[]
            {
                siteContent?.LastSync,
                siteMedia?.LastSync,
                siteCategories?.LastSync,
                siteTags?.LastSync
            }.Where(x => x.HasValue).DefaultIfEmpty().Max();

            return new GlobalContentSiteRow(
                site.Id,
                site.Name,
                siteContent?.Posts ?? 0,
                siteContent?.Pages ?? 0,
                siteMedia?.Count ?? 0,
                siteCategories?.Count ?? 0,
                siteTags?.Count ?? 0,
                siteContent?.Published ?? 0,
                siteContent?.Drafts ?? 0,
                lastSync,
                GetFreshness(lastSync));
        }).ToList();

        return new GlobalContentHubView(
            rows,
            rows.Sum(x => x.Posts),
            rows.Sum(x => x.Pages),
            rows.Sum(x => x.Media),
            rows.Sum(x => x.Categories),
            rows.Sum(x => x.Tags),
            rows.Count(x => x.TotalRecords > 0),
            rows.Count(x => x.Freshness is ContentCacheFreshness.Stale or ContentCacheFreshness.Empty));
    }

    private static ContentCacheFreshness GetFreshness(DateTime? lastSync)
    {
        if (!lastSync.HasValue) return ContentCacheFreshness.Empty;
        var age = DateTime.UtcNow - lastSync.Value;
        if (age <= TimeSpan.FromHours(6)) return ContentCacheFreshness.Fresh;
        if (age <= TimeSpan.FromHours(24)) return ContentCacheFreshness.Review;
        return ContentCacheFreshness.Stale;
    }
}

public sealed record GlobalContentHubView(
    IReadOnlyList<GlobalContentSiteRow> Sites,
    int Posts,
    int Pages,
    int Media,
    int Categories,
    int Tags,
    int SitesWithCachedData,
    int StaleSites);

public sealed record GlobalContentSiteRow(
    Guid SiteId,
    string SiteName,
    int Posts,
    int Pages,
    int Media,
    int Categories,
    int Tags,
    int Published,
    int Drafts,
    DateTime? LastSynchronizedAtUtc,
    ContentCacheFreshness Freshness)
{
    public int TotalRecords => Posts + Pages + Media + Categories + Tags;
}

public enum ContentCacheFreshness
{
    Empty,
    Fresh,
    Review,
    Stale
}
