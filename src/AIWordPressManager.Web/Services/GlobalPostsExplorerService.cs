using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class GlobalPostsExplorerService(AppDbContext dbContext)
{
    public Task<GlobalPostsExplorerResult> SearchAsync(Guid? siteId, string? status, string? query, int page, int pageSize, CancellationToken cancellationToken = default) =>
        SearchPostsAsync(siteId, status, query, page, pageSize, cancellationToken);

    public async Task<GlobalPostsExplorerResult> SearchPostsAsync(
        Guid? siteId,
        string? status,
        string? query,
        int page,
        int pageSize,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 10, 100);

        var source = dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.ContentType == "post" && x.IsAvailable);
        source = ApplyFilters(source, siteId, status, query, includeContent: false);

        var total = await source.CountAsync(cancellationToken);
        var totalPages = Math.Max(1, (int)Math.Ceiling(total / (double)pageSize));
        page = Math.Min(page, totalPages);

        var items = await source
            .OrderByDescending(x => x.ModifiedAtUtc ?? x.LastSynchronizedAtUtc)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .Join(dbContext.Sites.AsNoTracking(), content => content.SiteId, site => site.Id,
                (content, site) => new GlobalPostExplorerItem(content.SiteId, site.Name, content.WordPressId, content.Title, content.Slug, content.Status, content.Link, content.RenderedExcerpt, content.ModifiedAtUtc, content.LastSynchronizedAtUtc))
            .ToListAsync(cancellationToken);

        var summary = await dbContext.WordPressContentRecords.AsNoTracking()
            .Where(x => x.ContentType == "post" && x.IsAvailable)
            .GroupBy(_ => 1)
            .Select(group => new GlobalPostsSummary(group.Count(), group.Count(x => x.Status == "publish"), group.Count(x => x.Status == "draft"), group.Count(x => x.Status == "pending"), group.Count(x => x.Status == "future"), group.Select(x => x.SiteId).Distinct().Count(), group.Max(x => x.LastSynchronizedAtUtc)))
            .FirstOrDefaultAsync(cancellationToken)
            ?? new GlobalPostsSummary(0, 0, 0, 0, 0, 0, null);

        return new GlobalPostsExplorerResult(items, total, page, pageSize, totalPages, summary);
    }

    public async Task<GlobalPagesExplorerResult> SearchPagesAsync(
        Guid? siteId,
        string? status,
        string? query,
        int page,
        int pageSize,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 10, 100);

        var source = dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.ContentType == "page" && x.IsAvailable);
        source = ApplyFilters(source, siteId, status, query, includeContent: true);

        var total = await source.CountAsync(cancellationToken);
        var totalPages = Math.Max(1, (int)Math.Ceiling(total / (double)pageSize));
        page = Math.Min(page, totalPages);

        var items = await source
            .OrderByDescending(x => x.ModifiedAtUtc ?? x.LastSynchronizedAtUtc)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .Join(dbContext.Sites.AsNoTracking(), content => content.SiteId, site => site.Id,
                (content, site) => new GlobalPageExplorerItem(content.SiteId, site.Name, content.WordPressId, content.Title, content.Slug, content.Status, content.Link, content.RenderedExcerpt, content.ModifiedAtUtc, content.LastSynchronizedAtUtc))
            .ToListAsync(cancellationToken);

        var summary = await dbContext.WordPressContentRecords.AsNoTracking()
            .Where(x => x.ContentType == "page" && x.IsAvailable)
            .GroupBy(_ => 1)
            .Select(group => new GlobalPagesSummary(group.Count(), group.Count(x => x.Status == "publish"), group.Count(x => x.Status == "draft"), group.Count(x => x.Status == "pending"), group.Count(x => x.Status == "private"), group.Select(x => x.SiteId).Distinct().Count(), group.Max(x => x.LastSynchronizedAtUtc)))
            .FirstOrDefaultAsync(cancellationToken)
            ?? new GlobalPagesSummary(0, 0, 0, 0, 0, 0, null);

        return new GlobalPagesExplorerResult(items, total, page, pageSize, totalPages, summary);
    }

    private static IQueryable<AIWordPressManager.Domain.Entities.WordPressContentRecord> ApplyFilters(
        IQueryable<AIWordPressManager.Domain.Entities.WordPressContentRecord> source,
        Guid? siteId,
        string? status,
        string? query,
        bool includeContent)
    {
        if (siteId.HasValue) source = source.Where(x => x.SiteId == siteId.Value);

        if (!string.IsNullOrWhiteSpace(status) && !string.Equals(status, "all", StringComparison.OrdinalIgnoreCase))
        {
            var normalizedStatus = status.Trim().ToLowerInvariant();
            source = source.Where(x => x.Status == normalizedStatus);
        }

        if (!string.IsNullOrWhiteSpace(query))
        {
            var search = query.Trim();
            source = includeContent
                ? source.Where(x => EF.Functions.Like(x.Title, $"%{search}%") || EF.Functions.Like(x.Slug, $"%{search}%") || EF.Functions.Like(x.RenderedExcerpt, $"%{search}%") || EF.Functions.Like(x.RenderedContent, $"%{search}%"))
                : source.Where(x => EF.Functions.Like(x.Title, $"%{search}%") || EF.Functions.Like(x.Slug, $"%{search}%") || EF.Functions.Like(x.RenderedExcerpt, $"%{search}%"));
        }

        return source;
    }
}

public sealed record GlobalPostsExplorerResult(IReadOnlyList<GlobalPostExplorerItem> Items, int Total, int Page, int PageSize, int TotalPages, GlobalPostsSummary Summary);
public sealed record GlobalPostExplorerItem(Guid SiteId, string SiteName, int WordPressId, string Title, string Slug, string Status, string Link, string RenderedExcerpt, DateTime? ModifiedAtUtc, DateTime LastSynchronizedAtUtc);
public sealed record GlobalPostsSummary(int Total, int Published, int Drafts, int Pending, int Scheduled, int SiteCount, DateTime? LastSynchronizedAtUtc);