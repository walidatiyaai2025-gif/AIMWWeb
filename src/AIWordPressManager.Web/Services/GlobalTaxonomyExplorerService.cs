using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class GlobalTaxonomyExplorerService(AppDbContext dbContext, CurrentUserContext currentUser)
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

        var type = string.IsNullOrWhiteSpace(taxonomyType)
            ? "all"
            : taxonomyType.Trim().ToLowerInvariant();
        var search = query?.Trim();
        var ownerUserId = currentUser.RequireUserId();

        var ownedSites = await dbContext.Sites
            .AsNoTracking()
            .Where(x => x.OwnerUserId == ownerUserId)
            .OrderBy(x => x.Name)
            .Select(x => new OwnedSite(x.Id, x.Name))
            .ToListAsync(cancellationToken);

        if (ownedSites.Count == 0)
            return EmptyResult(pageSize);

        var ownedSiteIds = ownedSites.Select(x => x.Id).ToArray();
        var siteNames = ownedSites.ToDictionary(x => x.Id, x => x.Name);

        if (siteId.HasValue && !ownedSiteIds.Contains(siteId.Value))
            return EmptyResult(pageSize);

        var categoryQuery = dbContext.WordPressCategoryRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable && x.PostCount >= minimumUsage && ownedSiteIds.Contains(x.SiteId));

        var tagQuery = dbContext.WordPressTagRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable && x.PostCount >= minimumUsage && ownedSiteIds.Contains(x.SiteId));

        if (siteId.HasValue)
        {
            categoryQuery = categoryQuery.Where(x => x.SiteId == siteId.Value);
            tagQuery = tagQuery.Where(x => x.SiteId == siteId.Value);
        }

        var includeCategories = type is "all" or "category";
        var includeTags = type is "all" or "tag";
        var items = new List<GlobalTaxonomyItem>();

        if (includeCategories)
        {
            var categories = await categoryQuery
                .Select(x => new
                {
                    x.SiteId,
                    x.WordPressId,
                    x.Name,
                    x.Slug,
                    x.PostCount,
                    x.LastSynchronizedAtUtc
                })
                .ToListAsync(cancellationToken);

            items.AddRange(categories.Select(x => new GlobalTaxonomyItem(
                x.SiteId,
                siteNames.TryGetValue(x.SiteId, out var siteName) ? siteName : string.Empty,
                x.WordPressId,
                "category",
                x.Name,
                x.Slug,
                x.PostCount,
                x.LastSynchronizedAtUtc)));
        }

        if (includeTags)
        {
            var tags = await tagQuery
                .Select(x => new
                {
                    x.SiteId,
                    x.WordPressId,
                    x.Name,
                    x.Slug,
                    x.PostCount,
                    x.LastSynchronizedAtUtc
                })
                .ToListAsync(cancellationToken);

            items.AddRange(tags.Select(x => new GlobalTaxonomyItem(
                x.SiteId,
                siteNames.TryGetValue(x.SiteId, out var siteName) ? siteName : string.Empty,
                x.WordPressId,
                "tag",
                x.Name,
                x.Slug,
                x.PostCount,
                x.LastSynchronizedAtUtc)));
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            items = items.Where(x =>
                    x.Name.Contains(search, StringComparison.OrdinalIgnoreCase) ||
                    x.Slug.Contains(search, StringComparison.OrdinalIgnoreCase) ||
                    x.SiteName.Contains(search, StringComparison.OrdinalIgnoreCase))
                .ToList();
        }

        var orderedItems = items
            .OrderByDescending(x => x.PostCount)
            .ThenBy(x => x.Name, StringComparer.OrdinalIgnoreCase)
            .ToList();

        var total = orderedItems.Count;
        var totalPages = Math.Max(1, (int)Math.Ceiling(total / (double)pageSize));
        page = Math.Min(page, totalPages);
        var pagedItems = orderedItems
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .ToList();

        var summaryCategoryQuery = dbContext.WordPressCategoryRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable && ownedSiteIds.Contains(x.SiteId));
        var summaryTagQuery = dbContext.WordPressTagRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable && ownedSiteIds.Contains(x.SiteId));

        if (siteId.HasValue)
        {
            summaryCategoryQuery = summaryCategoryQuery.Where(x => x.SiteId == siteId.Value);
            summaryTagQuery = summaryTagQuery.Where(x => x.SiteId == siteId.Value);
        }

        var categoryRows = await summaryCategoryQuery
            .Select(x => new TaxonomySummaryRow(x.SiteId, x.PostCount, x.LastSynchronizedAtUtc))
            .ToListAsync(cancellationToken);
        var tagRows = await summaryTagQuery
            .Select(x => new TaxonomySummaryRow(x.SiteId, x.PostCount, x.LastSynchronizedAtUtc))
            .ToListAsync(cancellationToken);

        var allRows = categoryRows.Concat(tagRows).ToList();
        var summary = new GlobalTaxonomySummary(
            categoryRows.Count,
            tagRows.Count,
            allRows.Count(x => x.PostCount > 0),
            allRows.Count(x => x.PostCount == 0),
            allRows.Sum(x => x.PostCount),
            allRows.Select(x => x.SiteId).Distinct().Count(),
            allRows.Count == 0 ? null : allRows.Max(x => x.LastSynchronizedAtUtc));

        return new GlobalTaxonomyExplorerResult(pagedItems, total, page, pageSize, totalPages, summary);
    }

    private static GlobalTaxonomyExplorerResult EmptyResult(int pageSize) =>
        new([], 0, 1, pageSize, 1, new(0, 0, 0, 0, 0, 0, null));

    private sealed record OwnedSite(Guid Id, string Name);
    private sealed record TaxonomySummaryRow(Guid SiteId, int PostCount, DateTime LastSynchronizedAtUtc);
}

public sealed record GlobalTaxonomyExplorerResult(IReadOnlyList<GlobalTaxonomyItem> Items, int Total, int Page, int PageSize, int TotalPages, GlobalTaxonomySummary Summary);
public sealed record GlobalTaxonomyItem(Guid SiteId, string SiteName, int WordPressId, string TaxonomyType, string Name, string Slug, int PostCount, DateTime LastSynchronizedAtUtc);
public sealed record GlobalTaxonomySummary(int Categories, int Tags, int UsedTerms, int UnusedTerms, int TotalAssignments, int SiteCount, DateTime? LastSynchronizedAtUtc);