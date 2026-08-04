using AIWordPressManager.Application.Abstractions.Persistence;
using AIWordPressManager.Application.Abstractions.WordPress;
using Microsoft.EntityFrameworkCore;
using AIWordPressManager.Domain.Entities;

namespace AIWordPressManager.Persistence.WordPress;

public sealed class OfflineSnapshotService(AppDbContext dbContext) : IOfflineSnapshotService
{
    public async Task<WordPressExplorerSnapshot> LoadAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var content = await dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable).OrderByDescending(x => x.ModifiedAtUtc).ToListAsync(cancellationToken);
        var categories = await dbContext.WordPressCategoryRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable).OrderByDescending(x => x.PostCount).ToListAsync(cancellationToken);
        var tags = await dbContext.WordPressTagRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable).OrderByDescending(x => x.PostCount).ToListAsync(cancellationToken);
        var media = await dbContext.WordPressMediaRecords.AsNoTracking().Where(x => x.SiteId == siteId && x.IsAvailable).OrderByDescending(x => x.ModifiedAtUtc).ToListAsync(cancellationToken);

        WordPressContentItem MapContent(WordPressContentRecord x) => new(x.WordPressId, x.Title, x.Slug, x.Status, x.Link, x.ModifiedAtUtc is null ? null : new DateTimeOffset(DateTime.SpecifyKind(x.ModifiedAtUtc.Value, DateTimeKind.Utc)), x.RenderedContent, x.RenderedExcerpt);
        var posts = content.Where(x => x.ContentType == "post").Select(MapContent).ToArray();
        var pages = content.Where(x => x.ContentType == "page").Select(MapContent).ToArray();
        var categoryItems = categories.Select(x => new WordPressCategoryItem(x.WordPressId, x.Name, x.Slug, x.PostCount)).ToArray();
        var tagItems = tags.Select(x => new WordPressTagItem(x.WordPressId, x.Name, x.Slug, x.PostCount)).ToArray();
        var mediaItems = media.Select(x => new WordPressMediaItem(x.WordPressId, x.Title, x.Slug, x.MediaType, x.MimeType, x.SourceUrl, x.ModifiedAtUtc is null ? null : new DateTimeOffset(DateTime.SpecifyKind(x.ModifiedAtUtc.Value, DateTimeKind.Utc)))).ToArray();
        var last = content.Select(x => (DateTime?)x.LastSynchronizedAtUtc).Concat(categories.Select(x => (DateTime?)x.LastSynchronizedAtUtc)).Concat(tags.Select(x => (DateTime?)x.LastSynchronizedAtUtc)).Concat(media.Select(x => (DateTime?)x.LastSynchronizedAtUtc)).Max();
        return new(posts, pages, categoryItems, tagItems, mediaItems, posts.Length, pages.Length, categoryItems.Length, tagItems.Length, mediaItems.Length, last is null ? DateTimeOffset.MinValue : new DateTimeOffset(DateTime.SpecifyKind(last.Value, DateTimeKind.Utc)), WordPressSyncSummary.Empty);
    }

    public async Task<DateTimeOffset?> GetLastSyncAsync(Guid siteId, CancellationToken cancellationToken = default)
    {
        var value = await dbContext.WordPressContentRecords.AsNoTracking().Where(x => x.SiteId == siteId).MaxAsync(x => (DateTime?)x.LastSynchronizedAtUtc, cancellationToken);
        return value is null ? null : new DateTimeOffset(DateTime.SpecifyKind(value.Value, DateTimeKind.Utc));
    }
}
