using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class GlobalTaxonomyExplorerService(AppDbContext dbContext)
{
    public async Task<GlobalTaxonomyExplorerResult> SearchAsync(
        Guid? siteId,
        string? taxonomyType,
        string? query,
        int minimumUsage,
        int page,
        int pageSize,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 10, 100);
        minimumUsage = Math.Max(0, minimumUsage);
        var type = string.IsNullOrWhiteSpace(taxonomyType) ? "all" : taxonomyType.Trim().ToLowerInvariant();
        var search = query?.Trim();

        var categories = dbContext.WordPressCategoryRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable && x.PostCount >= minimumUsage)
            .Join(
                dbContext.Sites.AsNoTracking(),
                item => item.SiteId,
                site => site.Id,
                (item, site) => new GlobalTaxonomyItem(
                    item.SiteId,
                    site.Name,
                    item.WordPressId,
                    "category",
                    item.Name,
                    item.Slug,
                    item.PostCount,
                    item.LastSynchronizedAtUtc));

        var tags = dbContext.WordPressTagRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable && x.PostCount >= minimumUsage)
            .Join(
                dbContext.Sites.AsNoTracking(),
                item => item.SiteId,
                site => site.Id,
                (item, site) => new GlobalTaxonomyItem(
                    item.SiteId,
                    site.Name,
                    item.WordPressId,
                    "tag",
                    item.Name,
                    item.Slug,
                    item.PostCount,
                    item.LastSynchronizedAtUtc));

        IQueryable<GlobalTaxonomyItem> source = type switch
        {
            "category" => categories,
            "tag" => tags,
            _ => categories.Concat(tags)
        };

        if (siteId.HasValue)
        {
            source = source.Where(x => x.SiteId == siteId.Value);
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            source = source.Where(x =>
                EF.Functions.Like(x.Name, $"%{search}%") ||
                EF.Functions.Like(x.Slug, $"%{search}%") ||
                EF.Functions.Like(x.SiteName, $"%{search}%"));
        }

        var total = await source.CountAsync(cancellationToken);
        var totalPages = Math.Max(1, (int)Math.Ceiling(total / (double)pageSize));
        page = Math.Min(page, totalPages);

        var items = await source
            .OrderByDescending(x => x.PostCount)
            .ThenBy(x => x.Name)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToListAsync(cancellationToken);

        var categorySummary = await dbContext.WordPressCategoryRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .GroupBy(_ => 1)
            .Select(group => new
            {
                Count = group.Count(),
                Used = group.Count(x => x.PostCount > 0),
                Unused = group.Count(x => x.PostCount == 0),
                Usage = group.Sum(x => x.PostCount),
                Sites = group.Select(x => x.SiteId).Distinct().Count(),
                LastSync = group.Max(x => x.LastSynchronizedAtUtc)
            })
            .FirstOrDefaultAsync(cancellationToken);

        var tagSummary = await dbContext.WordPressTagRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .GroupBy(_ => 1)
            .Select(group => new
            {
                Count = group.Count(),
                Used = group.Count(x => x.PostCount > 0),
                Unused = group.Count(x => x.PostCount == 0),
                Usage = group.Sum(x => x.PostCount),
                Sites = group.Select(x => x.SiteId).Distinct().Count(),
                LastSync = group.Max(x => x.LastSynchronizedAtUtc)
            })
            .FirstOrDefaultAsync(cancellationToken);

        var summary = new GlobalTaxonomySummary(
            categorySummary?.Count ?? 0,
            tagSummary?.Count ?? 0,
            (categorySummary?.Used ?? 0) + (tagSummary?.Used ?? 0),
            (categorySummary?.Unused ?? 0) + (tagSummary?.Unused ?? 0),
            (categorySummary?.Usage ?? 0) + (tagSummary?.Usage ?? 0),
            Math.Max(categorySummary?.Sites ?? 0, tagSummary?.Sites ?? 0),
            Max(categorySummary?.LastSync, tagSummary?.LastSync));

        return new GlobalTaxonomyExplorerResult(items, total, page, pageSize, totalPages, summary);
    }

    private static DateTime? Max(DateTime? left, DateTime? right)
    {
        if (!left.HasValue) return right;
        if (!right.HasValue) return left;
        return left.Value >= right.Value ? left : right;
    }
}

public sealed record GlobalTaxonomyExplorerResult(
    IReadOnlyList<GlobalTaxonomyItem> Items,
    int Total,
    int Page,
    int PageSize,
    int TotalPages,
    GlobalTaxonomySummary Summary);

public sealed record GlobalTaxonomyItem(
    Guid SiteId,
    string SiteName,
    int WordPressId,
    string TaxonomyType,
    string Name,
    string Slug,
    int PostCount,
    DateTime LastSynchronizedAtUtc);

public sealed record GlobalTaxonomySummary(
    int Categories,
    int Tags,
    int UsedTerms,
    int UnusedTerms,
    int TotalAssignments,
    int SiteCount,
    DateTime? LastSynchronizedAtUtc);