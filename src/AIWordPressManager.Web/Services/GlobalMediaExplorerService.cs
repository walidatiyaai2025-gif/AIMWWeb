using AIWordPressManager.Persistence;
using Microsoft.EntityFrameworkCore;

namespace AIWordPressManager.Web.Services;

public sealed class GlobalMediaExplorerService(AppDbContext dbContext, CurrentUserContext currentUser)
{
    public async Task<GlobalMediaExplorerResult> SearchAsync(
        Guid? siteId,
        string? mediaType,
        string? query,
        int page,
        int pageSize,
        CancellationToken cancellationToken = default)
    {
        page = Math.Max(1, page);
        pageSize = Math.Clamp(pageSize, 12, 100);
        var ownerUserId = currentUser.RequireUserId();
        var ownedSites = dbContext.Sites.AsNoTracking().Where(x => x.OwnerUserId == ownerUserId);

        var source = dbContext.WordPressMediaRecords
            .AsNoTracking()
            .Where(x => x.IsAvailable)
            .Where(x => ownedSites.Any(site => site.Id == x.SiteId));

        if (siteId.HasValue)
            source = source.Where(x => x.SiteId == siteId.Value);

        if (!string.IsNullOrWhiteSpace(mediaType) && !string.Equals(mediaType, "all", StringComparison.OrdinalIgnoreCase))
        {
            var normalizedType = mediaType.Trim().ToLowerInvariant();
            source = source.Where(x => x.MediaType == normalizedType || x.MimeType.StartsWith(normalizedType + "/"));
        }

        if (!string.IsNullOrWhiteSpace(query))
        {
            var search = query.Trim();
            source = source.Where(x =>
                EF.Functions.Like(x.Title, $"%{search}%") ||
                EF.Functions.Like(x.Slug, $"%{search}%") ||
                EF.Functions.Like(x.MimeType, $"%{search}%") ||
                EF.Functions.Like(x.SourceUrl, $"%{search}%"));
        }

        var total = await source.CountAsync(cancellationToken);
        var totalPages = Math.Max(1, (int)Math.Ceiling(total / (double)pageSize));
        page = Math.Min(page, totalPages);

        var items = await source
            .OrderByDescending(x => x.ModifiedAtUtc ?? x.LastSynchronizedAtUtc)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .Join(
                ownedSites,
                media => media.SiteId,
                site => site.Id,
                (media, site) => new GlobalMediaExplorerItem(
                    media.SiteId,
                    site.Name,
                    media.WordPressId,
                    media.Title,
                    media.Slug,
                    media.MediaType,
                    media.MimeType,
                    media.SourceUrl,
                    media.ModifiedAtUtc,
                    media.LastSynchronizedAtUtc))
            .ToListAsync(cancellationToken);

        var summary = await source
            .GroupBy(_ => 1)
            .Select(group => new GlobalMediaSummary(
                group.Count(),
                group.Count(x => x.MediaType == "image" || x.MimeType.StartsWith("image/")),
                group.Count(x => x.MediaType == "video" || x.MimeType.StartsWith("video/")),
                group.Count(x => x.MediaType == "audio" || x.MimeType.StartsWith("audio/")),
                group.Count(x => x.MediaType == "application" || x.MimeType.StartsWith("application/")),
                group.Select(x => x.SiteId).Distinct().Count(),
                group.Max(x => x.LastSynchronizedAtUtc)))
            .FirstOrDefaultAsync(cancellationToken)
            ?? new GlobalMediaSummary(0, 0, 0, 0, 0, 0, null);

        return new GlobalMediaExplorerResult(items, total, page, pageSize, totalPages, summary);
    }
}

public sealed record GlobalMediaExplorerResult(IReadOnlyList<GlobalMediaExplorerItem> Items, int Total, int Page, int PageSize, int TotalPages, GlobalMediaSummary Summary);
public sealed record GlobalMediaExplorerItem(Guid SiteId, string SiteName, int WordPressId, string Title, string Slug, string MediaType, string MimeType, string SourceUrl, DateTime? ModifiedAtUtc, DateTime LastSynchronizedAtUtc);
public sealed record GlobalMediaSummary(int Total, int Images, int Videos, int Audio, int Documents, int SiteCount, DateTime? LastSynchronizedAtUtc);